<?php
/**
 * Terminus Plugin that contain a collection of commands useful during
 * the build step on a [Pantheon](https://www.pantheon.io) site that uses
 * a GitHub PR workflow.
 *
 * See README.md for usage information.
 */

namespace Pantheon\TerminusBuildTools\Commands;

use Consolidation\OutputFormatters\StructuredData\PropertyList;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\ProcessUtils;
use Consolidation\AnnotatedCommand\AnnotationData;
use Consolidation\AnnotatedCommand\CommandData;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Semver\Comparator;

/**
 * Env Delete Command
 */
class EnvDeleteCommand extends BuildToolsBase
{
    /**
     * Delete all of the build environments matching the pattern for transient
     * CI builds, i.e., all multidevs whose name begins with "ci-".
     *
     * @command build:env:delete:ci
     * @aliases build-env:delete:ci
     *
     * @param string $site_id Site name
     * @option keep Number of environments to keep
     * @option dry-run Only print what would be deleted; do not delete anything.
     */
    public function deleteBuildEnvCi(
        $site_id,
        $options = [
            'keep' => 0,
            'dry-run' => false,
        ])
    {
        // There should never be a PR that begins with the CI delete pattern,
        // but if there is, we will check for it and exclude that multidev
        // from consideration.
        $options['preserve-prs'] = true;

        // We always want to clean up the remote branch.
        $options['delete-branch'] = true;

        $options += [
            'keep' => 0,
            'preserve-if-branch' => false,
        ];

        return $this->deleteBuildEnv($site_id, self::TRANSIENT_CI_DELETE_PATTERN, $options);
    }

    /**
     * Delete all of the build environments matching the pattern for pull
     * request branches, i.e., all multidevs whose name begins with "pr-".
     *
     * @command build:env:delete:pr
     * @aliases build-env:delete:pr
     *
     * @param string $site_id Site name
     * @option dry-run Only print what would be deleted; do not delete anything.
     */
    public function deleteBuildEnvPR(
        $site_id,
        $options = [
            'dry-run' => false,
        ])
    {
        // Preserve any pull request that still has a corresponding branch in GitHub.
        $options['preserve-prs'] = true;

        // We always want to clean up the remote branch.
        $options['delete-branch'] = true;

        $options += [
            'keep' => 0,
            'preserve-if-branch' => false,
        ];

        return $this->deleteBuildEnv($site_id, self::PR_BRANCH_DELETE_PATTERN, $options);
    }

    /**
     * Delete all of the build environments matching the provided pattern,
     * optionally keeping a few of the most recently-created. Also, optionally
     * any environment that still has a remote branch on GitHub may be preserved.
     *
     * @command build:env:delete
     * @aliases build-env:delete
     *
     * @param string $site_id Site name
     * @param string $multidev_delete_pattern Pattern used for build environments
     * @option keep Number of environments to keep
     * @option preserve-prs Keep any environment that still has an open pull request associated with it.
     * @option preserve-if-branch Keep any environment that still has a remote branch that has not been deleted.
     * @option delete-branch Delete the git branch in the Pantheon repository in addition to the multidev environment.
     * @option dry-run Only print what would be deleted; do not delete anything.
     *
     * @deprecated This function can be too destructive if called from ci
     * using --yes with an overly-inclusive delete pattern, e.g. if an
     * environment variable for a recurring build is incorrectly altered.
     * Use build-env:delete:ci and build-env:delete:pr as safer alternatives.
     * This function will be removed in future versions.
     */
    public function deleteBuildEnv(
        $site_id,
        $multidev_delete_pattern = self::DEFAULT_DELETE_PATTERN,
        $options = [
            'keep' => 0,
            'preserve-prs' => false,
            'preserve-if-branch' => false,
            'delete-branch' => false,
            'dry-run' => false,
        ])
    {
        // Look up the oldest environments matching the delete pattern
        $oldestEnvironments = $this->oldestEnvironments($site_id, $multidev_delete_pattern);

        // Stop if nothing matched
        if (empty($oldestEnvironments)) {
            $this->log()->notice('No environments matched the provided pattern "{pattern}".', ['pattern' => $multidev_delete_pattern]);
            return;
        }

        // Reduce result list down to just the env id ('ci-123' et. al.)
        $oldestEnvironments = array_map(
            function ($item) {
                return $item['id'];
            },
            $oldestEnvironments
        );

        // Find the URL to the remote origin
        $remoteUrlFromGit = exec('git config --get remote.origin.url');

        // Find the URL of the remote origin stored in the build metadata
        $remoteUrl = $this->retrieveRemoteUrlFromBuildMetadata($site_id, $oldestEnvironments);

        // Bail if there is a URL mismatch
//        if (!empty($remoteUrlFromGit) && ($remoteUrlFromGit != $remoteUrl)) {
//            throw new TerminusException('Remote repository mismatch: local repository, {gitrepo} is different than the repository {metadatarepo} associated with the site {site}.', ['gitrepo' => $remoteUrlFromGit, 'metadatarepo' => $remoteUrl, 'site' => $site_id]);
//        }

        // Reduce result list down to just those that do NOT have open PRs.
        // We will use either the GitHub API or available git branches to check.
        $environmentsWithoutPRs = [];
        if (!empty($options['preserve-prs'])) {
            $github_token = getenv('GITHUB_TOKEN');
            // Call GitHub PR to get all open PRs.  Filter out matching branches
            // from this list that appear in $oldestEnvironments
            $environmentsWithoutPRs = $this->preserveEnvsWithOpenPRs($remoteUrl, $oldestEnvironments, $multidev_delete_pattern, $github_token);
        }
        elseif (!empty($options['preserve-if-branch'])) {
            $environmentsWithoutPRs = $this->preserveEnvsWithGitHubBranches($oldestEnvironments, $multidev_delete_pattern);
        }
        $environmentsToKeep = array_diff($oldestEnvironments, $environmentsWithoutPRs);
        $oldestEnvironments = $environmentsWithoutPRs;

        // Separate list into 'keep' and 'oldest' lists.
        if ($options['keep']) {
            $environmentsToKeep = array_merge(
                $environmentsToKeep,
                array_slice($oldestEnvironments, count($oldestEnvironments) - $options['keep'])
            );
            $oldestEnvironments = array_slice($oldestEnvironments, 0, count($oldestEnvironments) - $options['keep']);
        }

        // Make a display message of the environments to delete and keep
        $deleteList = implode(',', $oldestEnvironments);
        $keepList = implode(',', $environmentsToKeep);
        if (empty($keepList)) {
            $keepList = 'none of the build environments';
        }

        // Stop if there is nothing to delete.
        if (empty($oldestEnvironments)) {
            $this->log()->notice('Nothing to delete. Keeping {keepList}.', ['keepList' => $keepList,]);
            return;
        }

        if ($options['dry-run']) {
            $this->log()->notice('Dry run: would delete {deleteList} and keep {keepList}', ['deleteList' => $deleteList, 'keepList' => $keepList]);
            return;
        }

        if (!$this->confirm('Are you sure you want to delete {deleteList} and keep {keepList}?', ['deleteList' => $deleteList, 'keepList' => $keepList])) {
            return;
        }

        // Delete each of the selected environments.
        foreach ($oldestEnvironments as $env_id) {
            $site_env_id = "{$site_id}.{$env_id}";

            list (, $env) = $this->getSiteEnv($site_env_id);
            $this->deleteEnv($env, $options['delete-branch']);
        }
    }
}
