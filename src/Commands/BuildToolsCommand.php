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
use Symfony\Component\Console\Input\InputInterface;

/**
 * Build Tool Commands
 */
class BuildToolsCommand extends TerminusCommand implements SiteAwareInterface
{
    use SiteAwareTrait;

    const TRANSIENT_CI_DELETE_PATTERN = '^ci-';
    const PR_BRANCH_DELETE_PATTERN = '^pr-';
    const DEFAULT_DELETE_PATTERN = self::TRANSIENT_CI_DELETE_PATTERN;

    protected $tmpDirs = [];

    /**
     * Object constructor
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Register our shutdown function if any of our commands are executed.
     *
     * @hook init
     */
    public function initialize(InputInterface $input, AnnotationData $annotationData)
    {
        // Insure that $workdir will be deleted on exit.
        register_shutdown_function([$this, 'cleanup']);
    }

    /**
     * Create a new project from the requested source GitHub project.
     *  - Creates a GitHub repository forked from the source project.
     *  - Creates a Pantheon site to run the tests on.
     *  - Sets up Circle CI to test the repository.
     * In order to use this command, it is also necessary to provide
     * a set of secrets that are used to create the necessary projects,
     * and that are subsequentially cached in Circle CI for use during
     * the test run. Currently, these secrets must be provided via
     * environment variables; this keeps them out of the command history
     * and other places they may be inadvertantly observed.
     *
     * export TERMINUS_TOKEN machine_token_from_pantheon_dashboard
     * export GITHUB_TOKEN github_personal_access_token
     * export CIRCLE_TOKEN circle_personal_api_token
     *
     * @command build-env:create-project
     * @param string $source Packagist org/name of source template project to fork.
     * @param string $target Simple name of project to create.
     * @option org GitHub organization (defaults to authenticated user)
     * @option team Pantheon team
     * @option pantheon-site Name of Pantheon site to create (defaults to 'target' argument)
     * @option email email address to place in ssh-key
     * @option stability Minimum allowed stability for template project.
     * @option existing-github Use an existing github project rather than creating a new one. DEPRECATED and TEMPORARY. This option will be removed and replaced with a separate command.
     */
    public function createProject(
        $source,
        $target = '',
        $options = [
            'org' => '',
            'team' => null,
            'pantheon-site' => '',
            'label' => '',
            'email' => '',
            'test-site-name' => '',
            'admin-password' => '',
            'admin-email' => '',
            'stability' => '',
            'existing-github' => false,
        ])
    {
        // Copy options into ordinary variables
        $github_org = $options['org'];
        $team = $options['team'];
        $site_name = $options['pantheon-site'];
        $label = $options['label'];
        $git_email = $options['email'];
        $test_site_name = $options['test-site-name'];
        $admin_password = $options['admin-password'];
        $admin_email = $options['admin-email'];
        $stability = $options['stability'];

        // If only one parameter was provided, then it is the TARGET
        if (empty($target)) {
            $target = $source;
            $source = 'pantheon-systems/example-drops-8-composer';
        }

        // If someone provided a common alias, then replace it with its expanded value
        $source = $this->expandSourceAliases($source);

        // If an org was not provided for the source, then assume pantheon-systems
        if (strpos($source, '/') === FALSE) {
            $source = "pantheon-systems/$source";
        }

        // If an org was provided for the target, then extract it into
        // the `$org` variable
        if (strpos($target, '/') !== FALSE) {
            list($github_org, $target) = explode('/', $target, 2);
        }

        // If the user did not explicitly provide a Pantheon site name,
        // then use the target name for that purpose. This will probably
        // be the most common usage -- with matching GitHub / Pantheon
        // site names.
        if (empty($site_name)) {
            $site_name = $target;
        }

        // Provide default values for other optional variables.
        if (empty($label)) {
          $label = $site_name;
        }

        if (empty($test_site_name)) {
            $test_site_name = $site_name;
        }

        if (empty($admin_password)) {
            $admin_password = mt_rand();
        }

        if (empty($git_email)) {
            $git_email = exec('git config user.email');
        }

        if (empty($admin_email)) {
            $admin_email = $git_email;
        }

        // Before we begin, check to see if the requested site name is
        // available on Pantheon, and fail if it is not.
        $site_name = strtr(strtolower($site_name), '_ ', '--');
        if ($this->sites()->nameIsTaken($site_name)) {
            throw new TerminusException('The site name {site_name} is already taken on Pantheon.', compact('site_name'));
        }

        // We need to give Circle CI a machine token so that it can talk
        // to Pantheon. We cannot recover the token for the currently-authenticated
        // user, because security. Unfortunately, there is no API to generate
        // a new machine token. Otherwise, we'd just do that, which would have
        // the side benefit of providing a dedicated token for each site.
        $terminus_token = getenv('TERMINUS_TOKEN');
        $github_token = getenv('GITHUB_TOKEN');
        $circle_token = getenv('CIRCLE_TOKEN');

        if (empty($terminus_token)) {
            throw new TerminusException("Please generate a Pantheon machine token, as described in https://pantheon.io/docs/machine-tokens/. Then run: \n\nexport TERMINUS_TOKEN=my_machine_token_value");
        }

        if (empty($github_token)) {
            throw new TerminusException("Please generate a GitHub personal access token token, as described in https://help.github.com/articles/creating-an-access-token-for-command-line-use/. Then run: \n\nexport GITHUB_TOKEN=my_personal_access_token_value");
        }

        if (empty($circle_token)) {
            throw new TerminusException("Please generate a Circle CI personal API token token, as described in https://circleci.com/docs/api/#authentication. Then run: \n\nexport CIRCLE_TOKEN=my_personal_api_token_value");
        }

        // This target label is only used for the log messages below.
        $target_label = $target;
        if (!empty($github_org)) {
            $target_label = "$github_org/$target";
        }

        // Clone or create the github repository
        if ($options['existing-github']) {
            $this->log()->notice('Use existing GitHub project {target}', ['target' => $target_label]);
            list($target_project, $siteDir) = $this->cloneExistingGitHub($target, $github_org, $github_token);
        }
        else {
            $this->log()->notice('Create GitHub project {target} from {src}', ['src' => $source, 'target' => $target_label]);
            list($target_project, $siteDir) = $this->createGitHub($source, $target, $github_org, $github_token, $stability);
        }

        // Create an ssh key pair dedicated to use in these tests.
        // Change the email address to "user+ci-SITE@domain.com" so
        // that these keys can be differentiated in the Pantheon dashboard.
        $ssh_key_email = str_replace('@', "+ci-{$target}@", $git_email);
        $this->log()->notice('Create ssh key pair for {email}', ['email' => $ssh_key_email]);
        list($publicKey, $privateKey) = $this->createSshKeyPair($ssh_key_email, $site_name . '-key');
        $this->addPublicKeyToPantheonUser($publicKey);

        // Look up our upstream.
        $upstream = $this->autodetectUpstream($siteDir);

        // Push our site to Pantheon.
        $this->createPantheonSite($site_name, $siteDir, $label, $team, $upstream);

        // Look up the site UUID for the Pantheon dashboard link
        $site = $this->getSite($site_name);
        $siteInfo = $site->serialize();
        $site_uuid = $siteInfo['id'];

        $this->log()->notice('Created a new Pantheon site with UUID {uuid}', ['uuid' => $site_uuid]);

        // Create a new README file to point to this project's Circle tests and the dev site on Pantheon
        if (!$options['existing-github']) {
            $badgeTargetLabel = strtr($target, '-', '_');
            $source_project = $this->sourceProjectFromSource($source);
            $circleBadge = "[![CircleCI](https://circleci.com/gh/{$source_project}.svg?style=svg)](https://circleci.com/gh/{$target_project})";
            $pantheonBadge = "[![Pantheon {$target}](https://img.shields.io/badge/pantheon-{$badgeTargetLabel}-yellow.svg)](https://dashboard.pantheon.io/sites/{$site_uuid}#dev/code)";
            $siteBadge = "[![Dev Site {$target}](https://img.shields.io/badge/site-{$badgeTargetLabel}-blue.svg)](http://dev-{$target}.pantheonsite.io/)";
            $readme = "# $target\n\n$circleBadge $pantheonBadge $siteBadge";
            file_put_contents("$siteDir/README.md", $readme);

            $this->log()->notice('Make initial commit to GitHub');

            // Make the initial commit to our GitHub repository
            $this->initialCommit($siteDir);
        }

        $this->log()->notice('Push code to Pantheon');

        // Push code to newly-created project.
        $metadata = $this->pushCodeToPantheon("{$site_name}.dev", 'dev', $siteDir);

        $this->log()->notice('Install the site on the dev environment');

        // Install the site.
        $site_install_options = [
            'account-mail' => $admin_email,
            'account-name' => 'admin',
            'account-pass' => $admin_password,
            'site-mail' => $admin_email,
            'site-name' => $test_site_name
        ];
        $this->installSite("{$site_name}.dev", $siteDir, $site_install_options);

        $this->log()->notice('Configure Circle CI');

        // Set up Circle CI and run our first test.
        $circle_env = [
            'TERMINUS_TOKEN' => $terminus_token,
            'GITHUB_TOKEN' => $github_token,
            'TERMINUS_SITE' => $site_name,
            'TEST_SITE_NAME' => $test_site_name,
            'ADMIN_PASSWORD' => $admin_password,
            'ADMIN_EMAIL' => $admin_email,
            'GIT_EMAIL' => $git_email,
        ];

        $circle_url = "https://circleci.com/api/v1.1/project/github/$target_project";
        $this->setCircleEnvironmentVars($circle_url, $circle_token, $circle_env);
        $this->addPrivateKeyToCircleProject($circle_url, $circle_token, $privateKey);
    }

    /**
     * Provide some simple aliases to reduce typing when selecting common repositories
     */
    protected function expandSourceAliases($source)
    {
        $aliases = [
            'example-drops-8-composer' => ['d8', 'drops-8'],
            'example-drops-7-composer' => ['d7', 'drops-7'],
            // 'example-wordpress-composer' => ['wp', 'wordpress'],
        ];

        $map = [strtolower($source) => $source];
        foreach ($aliases as $full => $shortcuts) {
            foreach ($shortcuts as $alias) {
                $map[$alias] = $full;
            }
        }

        return $map[strtolower($source)];
    }

    /**
     * Detect the upstream to use based on the contents of the source repository.
     * Upstream is irrelevant, save for the fact that this is the only way to
     * set the framework on Pantheon at the moment.
     */
    protected function autodetectUpstream($siteDir)
    {
        // or 'Drupal 7' or 'WordPress'
        return 'Drupal 8';
    }

    protected function createSshKeyPair($ssh_key_email, $prefix = 'id')
    {
        $tmpkeydir = $this->tempdir('ssh-keys');

        $privateKey = "$tmpkeydir/$prefix";
        $publicKey = "$privateKey.pub";

        $this->passthru("ssh-keygen -t rsa -b 4096 -f $privateKey -N '' -C '$ssh_key_email'");

        return [$publicKey, $privateKey];
    }

    protected function cloneExistingGitHub($target, $github_org, $github_token)
    {
        $target_org = $github_org;
        if (empty($github_org)) {
            $userData = $this->curlGitHub('user', [], $github_token);
            $target_org = $userData['login'];
        }
        $target_project = "$target_org/$target";

        $tmpsitedir = $this->tempdir('local-site');
        $local_site_path = "$tmpsitedir/$target:dev-master";

        $this->passthru("composer create-project $target_project $local_site_path -n --keep-vcs --stability dev");

        return [$target_project, $local_site_path];
    }

    protected function createGitHub($source, $target, $github_org, $github_token, $stability = '')
    {
        // We need a different URL here if $github_org is an org; if no
        // org is provided, then we use a simpler URL to create a repository
        // owned by the currently-authenitcated user.
        $createRepoUrl = "orgs/$github_org/repos";
        $target_org = $github_org;
        if (empty($github_org)) {
            $createRepoUrl = 'user/repos';
            $userData = $this->curlGitHub('user', [], $github_token);
            $target_org = $userData['login'];
        }
        $target_project = "$target_org/$target";
        $remote_url = "git@github.com:${target_project}.git";

        $source_project = $this->sourceProjectFromSource($source);
        $tmpsitedir = $this->tempdir('local-site');

        $local_site_path = "$tmpsitedir/$target";

        $this->log()->notice('Creating project and resolving dependencies.');

        // If the source is 'org/project:dev-branch', then automatically
        // set the stability to 'dev'.
        if (empty($stability) && preg_match(':dev-', $source)) {
            $stability = 'dev';
        }
        // Pass in --stability to `composer create-project` if user requested it.
        $stability_flag = empty($stability) ? '' : "--stability $stability";

        // TODO: Do we need to remove $local_site_path/.git? (-n should obviate this need)
        $this->passthru("composer create-project $source $local_site_path -n $stability_flag");

        $this->log()->notice('Creating repository {repo} from {source}', ['repo' => $remote_url, 'source' => $source]);
        $postData = ['name' => $target];
        $result = $this->curlGitHub($createRepoUrl, $postData, $github_token);
        $this->log()->debug('Result of creating GitHub project is {result}', ['result' => var_export($result, true)]);

        // Create a GitHub repository
        $this->passthru("git -C $local_site_path init");
        $this->passthru("git -C $local_site_path remote add origin $remote_url");

        return [$target_project, $local_site_path];
    }

    /**
     * Given a source, such as:
     *    pantheon-systems/example-drops-8-composer:dev-lightning-fist-2
     * Return the 'project' portion, including the org, e.g.:
     *    pantheon-systems/example-drops-8-composer
     */
    protected function sourceProjectFromSource($source)
    {
        return preg_replace('/:.*//', '', $source);
    }

    protected function initialCommit($local_site_path)
    {
        // Add the canonical repository files to the new GitHub project
        // respecting .gitignore.
        $this->passthru("git -C $local_site_path add .");
        $this->passthru("git -C $local_site_path commit -m 'Initial commit'");
        $this->passthru("git -C $local_site_path push --set-upstream origin master");

    }

    /**
     * For testing, show the authenticated user information
     *
     * @command build-env:github-user
     *
     * @return RowsOfFields
     */
    public function authenticatedUser()
    {
        $github_token = getenv('GITHUB_TOKEN');
        $result = $this->curlGitHub('user', [], $github_token);
        return $result;
    }

    /**
     * For testing, show the user's available upstreams.
     *
     * @command build-env:upstreams
     * @return RowsOfFields
     */
    public function showUpstreams()
    {
        $user = $this->session()->getUser();
        return $user->getUpstreams()->serialize();
    }

    protected function createPantheonSite($site_id, $siteDir, $label, $team, $upstream)
    {
        $this->log()->debug('Creating site {name} in org {org} with upstream {upstream}', ['name' => $site_id, 'org' => $team, 'upstream' => $upstream]);
        $this->siteCreate($site_id, $label, $upstream, ['org' => $team]);
    }

    // TODO: if we could look up the commandfile for
    // Pantheon\Terminus\Commands\Site\CreateCommand,
    // then we could just call its 'create' method
    public function siteCreate($site_name, $label, $upstream_id, $options = ['org' => null,])
    {
        if ($this->sites()->nameIsTaken($site_name)) {
            throw new TerminusException('The site name {site_name} is already taken.', compact('site_name'));
        }

        $workflow_options = [
            'label' => $label,
            'site_name' => $site_name
        ];
        $user = $this->session()->getUser();

        // Locate upstream
        $upstream = $user->getUpstreams()->get($upstream_id);

        // Locate organization
        if (!empty($org_id = $options['org'])) {
            $org = $user->getOrgMemberships()->get($org_id)->getOrganization();
            $workflow_options['organization_id'] = $org->id;
        }

        // Create the site
        $this->log()->notice('Creating a new Pantheon site {name}', ['name' => $site_name]);
        $workflow = $this->sites()->create($workflow_options);
        while (!$workflow->checkProgress()) {
            // @TODO: Add Symfony progress bar to indicate that something is happening.
        }

        // Deploy the upstream
        if ($site = $this->getSite($workflow->get('waiting_for_task')->site_id)) {
            $this->log()->notice('Deploying {upstream} to Pantheon site', ['upstream' => $upstream_id]);
            $workflow = $site->deployProduct($upstream->id);
            while (!$workflow->checkProgress()) {
                // @TODO: Add Symfony progress bar to indicate that something is happening.
            }
            $this->log()->notice('Deployed CMS');
        }
    }

    protected function setCircleEnvironmentVars($circle_url, $token, $env)
    {
        foreach ($env as $key => $value) {
            $data = ['name' => $key, 'value' => $value];
            $this->curl($token, $data, "$circle_url/envvar");
        }
        $this->curl($token, [], "$circle_url/follow");
    }

    protected function addPublicKeyToPantheonUser($publicKey)
    {
        $this->session()->getUser()->getSSHKeys()->addKey($publicKey);
    }

    protected function addPrivateKeyToCircleProject($circle_url, $token, $privateKey)
    {
        $privateKeyContents = file_get_contents($privateKey);
        $data = [
            'hostname' => 'drush.in',
            'private_key' => $privateKeyContents,
        ];
        $this->curl($token, $data, "$circle_url/ssh-key");
    }

    protected function curl($token, $data, $url)
    {
        $json = json_encode($data);

        // TODO: Maybe use PHP curl API?
        $this->passthru("curl -u {$token}: -X POST --header 'Content-Type: application/json' -d '$json' $url");
    }

    /**
     * Create the specified multidev environment on the given Pantheon
     * site from the build assets at the current working directory.
     *
     * @command build-env:create
     * @param string $site_env_id The site and env of the SOURCE
     * @param string $multidev The name of the env to CREATE
     * @option label What to name the environment in commit comments
     * @option notify Command to exec to notify when a build environment is created
     */
    public function createBuildEnv(
        $site_env_id,
        $multidev,
        $options = [
            'label' => '',
            'notify' => '',
        ])
    {
        list($site, $env) = $this->getSiteEnv($site_env_id);
        $env_id = $env->getName();
        $env_label = $multidev;
        if (!empty($options['label'])) {
            $env_label = $options['label'];
        }

        // Fetch the site id also
        $siteInfo = $site->serialize();
        $site_id = $siteInfo['id'];

        // Check to see if '$multidev' already exists on Pantheon.
        $environmentExists = $site->getEnvironments()->has($multidev);

        // Check to see if pantheon.yml has been modified.
        if (!$environmentExists && $this->commitChangesFile('HEAD', 'pantheon.yml')) {
            // If it does, then we need to create the environment
            // in advance, before we push our change. It is more
            // efficient to push the branch first, and then create
            // the multidev, as in this instance, we do not need
            // to call waitForCodeSync(). However, changes to pantheon.yml
            // will not be applied unless we push our change.
            // To allow pantheon.yml to be processed, we will
            // create the multidev environment, and then push the code.
            $this->create($site_env_id, $multidev);
            $environmentExists = true;
        }

        $metadata = $this->pushCodeToPantheon($site_env_id, $multidev, '', $env_label);

        // Create a new environment for this test.
        if (!$environmentExists) {
            // If the environment is created after the branch is pushed,
            // then there is never a race condition -- the new env is
            // created with the correct files from the specified branch.
            $this->create($site_env_id, $multidev);
        }

        // Clear the environments, so that they will be re-fetched.
        // Otherwise, the new environment will not be found immediately
        // after it is first created. If we set the connection mode to
        // git mode, then Terminus will think it is still in sftp mode
        // if we do not re-fetch.
        $site->environments = null;

        // Get (or re-fetch) a reference to our target multidev site.
        $target_env = $site->getEnvironments()->get($multidev);

        // Set the target environment to sftp mode
        $this->connectionSet($target_env, 'sftp');

        // If '--notify' was passed, then exec the notify command
        if (!empty($options['notify'])) {
            $site_name = $site->getName();
            $project = $this->projectFromRemoteUrl($metadata['url']);
            $metadata += [
                'project' => $project,
                'site-id' => $site_id,
                'site' => $site_name,
                'env' => $multidev,
                'label' => $env_label,
                'dashboard-url' => "https://dashboard.pantheon.io/sites/{$site_id}#{$multidev}",
                'site-url' => "https://{$multidev}-{$site_name}.pantheonsite.io/",
            ];

            $command = $this->interpolate($options['notify'], $metadata);

            // Run notification command. Ignore errors.
            passthru($command);
        }
    }

    /**
     * Install the apporpriate CMS on the newly-created Pantheon site.
     *
     * @command build-env:install-site
     */
    public function installSite(
        $site_env_id,
        $siteDir = '',
        $site_install_options = [
            'account-mail' => '',
            'account-name' => '',
            'account-pass' => '',
            'site-mail' => '',
            'site-name' => ''
        ])
    {
        // TODO: Detect WordPress sites and use wp-cli to install.
        list($site, $env) = $this->getSiteEnv($site_env_id);

        $this->log()->notice('Install site on {site}', ['site' => $site_env_id]);

        // Set the target environment to sftp mode prior to installation
        $this->connectionSet($env, 'sftp');

        $command_line = "drush site-install --yes";
        foreach ($site_install_options as $option => $value) {
            if (!empty($value)) {
                $command_line .= " --$option=" . $this->escapeArgument($value);
            }
        }
        $this->log()->notice("Install site via {cmd}", ['cmd' => $command_line]);
        $result = $env->sendCommandViaSsh(
            $command_line,
            function ($type, $buffer) {
            }
        );
        $output = $result['output'];
    }

    /**
     * Escape one command-line arg
     *
     * @param string $arg The argument to escape
     * @return string
     */
    protected function escapeArgument($arg)
    {
        // Omit escaping for simple args.
        if (preg_match('/^[a-zA-Z0-9_-]*$/', $arg)) {
            return $arg;
        }
        return ProcessUtils::escapeArgument($arg);
    }

    /**
     * Push code to a specific Pantheon site and environment that already exists.
     *
     * @param string $site_env_id Site and environment to push to. May be any dev or multidev environment.
     * @param string $repositoryDir Code to push. Defaults to cwd.
     */
    public function pushCode(
        $site_env_id,
        $repositoryDir = '',
        $options = [
          'label' => '',
        ])
    {
        return $this->pushCodeToPantheon($site_env_id, '', $repositoryDir, $options['label']);
    }

    /**
     * Push code to Pantheon -- common routine used by 'create-project', 'create' and 'push-code' commands.
     */
    public function pushCodeToPantheon(
        $site_env_id,
        $multidev = '',
        $repositoryDir = '',
        $label = '')
    {
        list($site, $env) = $this->getSiteEnv($site_env_id);
        $env_id = $env->getName();
        $multidev = empty($multidev) ? $env_id : $multidev;
        $branch = ($multidev == 'dev') ? 'master' : $multidev;
        $env_label = $multidev;
        if (!empty($label)) {
            $env_label = $label;
        }

        // Fetch the site id also
        $siteInfo = $site->serialize();
        $site_id = $siteInfo['id'];
        if (empty($repositoryDir)) {
            $repositoryDir = getcwd();
        }

        // Check to see if '$multidev' already exists on Pantheon.
        $environmentExists = $site->getEnvironments()->has($multidev);

        // Add a remote named 'pantheon' to point at the Pantheon site's git repository.
        // Skip this step if the remote is already there (e.g. due to CI service caching).
        $this->addPantheonRemote($env, $repositoryDir);
        // $this->passthru("git -C $repositoryDir fetch pantheon");

        // Record the metadata for this build
        $metadata = $this->getBuildMetadata($repositoryDir);
        $this->recordBuildMetadata($metadata, $repositoryDir);

        // Create a new branch and commit the results from anything that may
        // have changed. We presume that the source repository is clean of
        // any unwanted files prior to the build step (e.g. after a clean
        // checkout in a CI environment.)
        $this->passthru("git -C $repositoryDir checkout -B $branch");
        $this->passthru("git -C $repositoryDir add --force -A .");

        // Remove any .git files added above from the set of files being
        // committed. Ideally, there will be none.
        $finder = new Finder();
        $fs = new Filesystem();
        foreach (
            $finder
                ->directories()
                ->in($repositoryDir)
                ->ignoreDotFiles(false)
                ->ignoreVCS(false)
                ->depth('> 0')
                ->name('.git')
            as $dir) {
            $fs->remove($dir->getRelativePathname());
        }

        // Now that everything is ready, commit the build artifacts.
        $this->passthru("git -C $repositoryDir commit -q -m 'Build assets for $env_label.'");

        // If the environment does exist, then we need to be in git mode
        // to push the branch up to the existing multidev site.
        if ($environmentExists) {
            $target_env = $site->getEnvironments()->get($multidev);
            $this->connectionSet($target_env, 'git');
        }

        // Push the branch to Pantheon
        $preCommitTime = time();
        $this->passthru("git -C $repositoryDir push --force -q pantheon $branch");

        // If the environment already existed, then we risk encountering
        // a race condition, because the 'git push' above will fire off
        // an asynchronous update of the existing update. If we switch to
        // sftp mode before this sync is completed, then the converge that
        // sftp mode kicks off will corrupt the environment.
        if ($environmentExists) {
            $target_env = $site->getEnvironments()->get($multidev);
            $this->waitForCodeSync($preCommitTime, $site, $target_env);
        }

        return $metadata;
    }

    protected function projectFromRemoteUrl($url)
    {
        return preg_replace('#[^:/]*[:/]([^/:]*/[^.]*)\.git#', '\1', str_replace('https://', '', $url));
    }

    /**
     * @command build-env:merge
     * @param string $site_env_id The site and env to merge and delete
     * @option label What to name the environment in commit comments
     */
    public function mergeBuildEnv($site_env_id, $options = ['label' => ''])
    {
        // c.f. merge-pantheon-multidev script
        list($site, $env) = $this->getSiteEnv($site_env_id);
        $env_id = $env->getName();
        $env_label = $env_id;
        if (!empty($options['label'])) {
            $env_label = $options['label'];
        }

        // If we are building against the 'dev' environment, then simply
        // commit the changes once the PR is merged.
        if ($env_id == 'dev') {
            $env->commitChanges("Build assets for $env_label.");
            return;
        }

        // When using build-env:merge, we expect that the dev environment
        // should stay in git mode. We will switch it to git mode now to be sure.
        $dev_env = $site->getEnvironments()->get('dev');
        $this->connectionSet($dev_env, 'git');

        // Replace the entire contents of the master branch with the branch we just tested.
        $this->passthru('git checkout master');
        $this->passthru("git merge -q -m 'Merge build assets from test $env_label.' -X theirs $env_id");

        // Push our changes back to the dev environment, replacing whatever was there before.
        $this->passthru('git push --force -q pantheon master');

        // Once the build environment is merged, we do not need it any more
        $this->deleteEnv($env, true);
    }

    /**
     * Delete all of the build environments matching the provided pattern,
     * optionally keeping a few of the most recently-created. Also, optionally
     * any environment that still has a remote branch on GitHub may be preserved.
     *
     * @command build-env:delete
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
        if (!empty($remoteUrlFromGit) && ($remoteUrlFromGit != $remoteUrl)) {
            throw new TerminusException('Remote repository mismatch: local repository, {gitrepo} is different than the repository {metadatarepo} associated with the site {site}.', ['gitrepo' => $remoteUrlFromGit, 'metadatarepo' => $remoteUrl, 'site' => $site_id]);
        }

        // Reduce result list down to just those that do NOT have open PRs.
        // We will use either the GitHub API or available git branches to check.
        $environmentsWithoutPRs = [];
        if (!empty($options['preserve-prs'])) {
            // Call GitHub PR to get all open PRs.  Filter out matching branches
            // from this list that appear in $oldestEnvironments
            $environmentsWithoutPRs = $this->preserveEnvsWithOpenPRs($remoteUrl, $oldestEnvironments, $multidev_delete_pattern);
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

    protected function preserveEnvsWithOpenPRs($remoteUrl, $oldestEnvironments, $multidev_delete_pattern)
    {
        $project = $this->projectFromRemoteUrl($remoteUrl);
        $branchList = $this->branchesForOpenPullRequests($project);
        return $this->filterBranches($oldestEnvironments, $branchList, $multidev_delete_pattern);
    }

    function branchesForOpenPullRequests($project)
    {
        $data = $this->curlGitHub("repos/$project/pulls?state=open");

        $branchList = array_map(
            function ($item) {
                return $item['head']['ref'];
            },
            $data
        );

        return $branchList;
    }

    protected function createGitHubCurlChannel($uri, $postData = [], $auth = '')
    {
        $url = "https://api.github.com/$uri";

        $headers = [
            'Content-Type: application/json',
            'User-Agent: pantheon/terminus-build-tools-plugin'
        ];

        if (!empty($auth)) {
            $headers[] = "Authorization: token $auth";
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if (!empty($postData)) {
            $payload = json_encode($postData);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        return $ch;
    }

    public function curlGitHub($uri, $postData = [], $auth = '')
    {
        $ch = $this->createGitHubCurlChannel($uri, $postData, $auth);
        $result = curl_exec($ch);
        if(curl_errno($ch))
        {
            throw new TerminusException(curl_error($ch));
        }
        $data = json_decode($result, true);
        curl_close($ch);

        if (isset($data['errors'])) {
            $errors = [];
            foreach ($data['errors'] as $error) {
                $errors[] = $error['message'];
            }
            throw new TerminusException('GitHub error: {message}. {errors}', ['message' => $data['message'], 'errors' => implode("\n", $errors)]);
        }

        return $data;
    }

    /**
     * Delete all of the build environments matching the pattern for transient
     * CI builds, i.e., all multidevs whose name begins with "ci-".
     *
     * @command build-env:delete:ci
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
     * @command build-env:delete:pr
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

    // TODO: At the moment, this takes multidev environment names,
    // e.g.:
    //   pr-dc-worka
    // And compares them against a list of branches, e.g.:
    //   dc-workaround
    //   lightning-fist-2
    //   composer-merge-pantheon
    // In its current form, the 'pr-' is stripped from the beginning of
    // the environment name, and then a 'begins-with' test is done. This
    // is not perfect, but if it goes wrong, the result will be that a
    // multidev environment that should have been eligible for deletion will
    // not be deleted.
    //
    // This could be made better if we could fetch the build-metadata.json
    // file from the repository root of each multidev environment, which would
    // give us the correct branch name for every environment. We could do
    // this without too much trouble via rsync; this might be a little slow, though.
    protected function preserveEnvsWithGitHubBranches($oldestEnvironments, $multidev_delete_pattern)
    {
        $remoteBranch = 'origin';

        // Update the local repository -- prune / add remote branches.
        // We could use `git remote prune origin` to only prune remote branches.
        $this->passthru('git remote update --prune origin');

        // List all of the remote branches
        $outputLines = $this->exec('git branch -ar');

        // Remove branch lines that do not begin with 'origin/'
        $outputLines = array_filter(
            $outputLines,
            function ($item) use ($remoteBranch) {
                return preg_match("%^ *$remoteBranch/%", $item);
            }
        );

        // Strip the 'origin/' from the beginning of each branch line
        $outputLines = array_map(
            function ($item) use ($remoteBranch) {
                return preg_replace("%^ *$remoteBranch/%", '', $item);
            },
            $outputLines
        );

        return $this->filterBranches($oldestEnvironments, $outputLines, $multidev_delete_pattern);
    }

    protected function filterBranches($oldestEnvironments, $branchList, $multidev_delete_pattern)
    {
        // Filter environments that have matching remote branches in origin
        return array_filter(
            $oldestEnvironments,
            function ($item) use ($branchList, $multidev_delete_pattern) {
                $match = $item;
                // If the name is less than the maximum length, then require
                // an exact match; otherwise, do a 'starts with' test.
                if (strlen($item) < 11) {
                    $match .= '$';
                }
                // Strip the multidev delete pattern from the beginning of
                // the match. The multidev env name was composed by prepending
                // the delete pattern to the branch name, so this recovers
                // the branch name.
                $match = preg_replace("%$multidev_delete_pattern%", '', $match);
                // Constrain match to only match from the beginning
                $match = "^$match";

                // Find items in $branchList that match $match.
                $matches = preg_grep ("%$match%i", $branchList);
                return empty($matches);
            }
        );
    }

    protected function deleteEnv($env, $deleteBranch = false)
    {
        $workflow = $env->delete(['delete_branch' => $deleteBranch,]);
        $workflow->wait();
        if ($workflow->isSuccessful()) {
            $this->log()->notice('Deleted the multidev environment {env}.', ['env' => $env->id,]);
        } else {
            throw new TerminusException($workflow->getMessage());
        }
    }

    /**
     * Displays a list of the site's ci build environments, sorted with oldest first.
     *
     * @command build-env:list
     * @authorize
     *
     * @field-labels
     *     id: ID
     *     created: Created
     *     domain: Domain
     *     connection_mode: Connection Mode
     *     locked: Locked
     *     initialized: Initialized
     * @return RowsOfFields
     *
     * @param string $site_id Site name
     * @param string $multidev_delete_pattern Pattern identifying ci build environments
     * @usage env:list <site>
     *    Displays a list of <site>'s environments.
     */
    public function listOldest($site_id, $multidev_delete_pattern = self::DEFAULT_DELETE_PATTERN) {
        $siteList = $this->oldestEnvironments($site_id, $multidev_delete_pattern);

        return new RowsOfFields($siteList);
    }

    /**
     * Return a list of multidev environments matching the provided
     * pattern, sorted with oldest first.
     *
     * @param string $site_id Site to check.
     * @param string $multidev_delete_pattern Regex of environments to select.
     */
    protected function oldestEnvironments($site_id, $multidev_delete_pattern)
    {
        // Get a list of all of the sites
        $env_list = $this->getSite($site_id)->getEnvironments()->serialize();

        // Filter out the environments that do not match the multidev delete pattern
        $env_list = array_filter(
            $env_list,
            function ($item) use ($multidev_delete_pattern) {
                return preg_match("%$multidev_delete_pattern%", $item['id']);
            }
        );

        // Sort the environments by creation date, with oldest first
        uasort(
            $env_list,
            function ($a, $b) {
                if ($a['created'] == $b['created']) {
                    return 0;
                }
                return ($a['created'] < $b['created']) ? -1 : 1;
            }
        );

        return $env_list;
    }

    /**
     * Create a new multidev environment
     *
     * @param string $site_env Source site and environment.
     * @param string $multidev Name of environment to create.
     */
    public function create($site_env, $multidev)
    {
        list($site, $env) = $this->getSiteEnv($site_env, 'dev');
        $this->log()->notice("Creating multidev {env} for site {site}", ['site' => $site->getName(), 'env' => $multidev]);
        $workflow = $site->getEnvironments()->create($multidev, $env);
        while (!$workflow->checkProgress()) {
            // TODO: Add workflow progress output
        }
        $this->log()->notice($workflow->getMessage());
    }

    /**
     * Set the connection mode to 'sftp' or 'git' mode, and wait for
     * it to complete.
     *
     * @param Pantheon\Terminus\Models\Environment $env
     * @param string $mode
     */
    public function connectionSet($env, $mode)
    {
        $workflow = $env->changeConnectionMode($mode);
        if (is_string($workflow)) {
            $this->log()->notice($workflow);
        } else {
            while (!$workflow->checkProgress()) {
                // TODO: Add workflow progress output
            }
            $this->log()->notice($workflow->getMessage());
        }
    }

    /**
     * Wait for a workflow to complete.
     *
     * @param int $startTime Ignore any workflows that started before the start time.
     * @param string $workflow The workflow message to wait for.
     */
    protected function waitForCodeSync($startTime, $site, $env)
    {
        $env_id = $env->getName();
        $expectedWorkflowDescription = "Sync code on \"$env_id\"";

        // Wait for at most one minute.
        $startWaiting = time();
        while(time() - $startWaiting < 60) {
            $workflow = $this->getLatestWorkflow($site);
            $workflowCreationTime = $workflow->get('created_at');
            $workflowDescription = $workflow->get('description');

            if (($workflowCreationTime > $startTime) && ($expectedWorkflowDescription == $workflowDescription)) {
                $this->log()->notice("Workflow '{current}' {status}.", ['current' => $workflowDescription, 'status' => $workflow->getStatus(), ]);
                if ($workflow->isSuccessful()) {
                    return;
                }
            }
            else {
                $this->log()->notice("Current workflow is '{current}'; waiting for '{expected}'", ['current' => $workflowDescription, 'expected' => $expectedWorkflowDescription]);
            }
            // Wait a bit, then spin some more
            sleep(5);
        }
    }

    /**
     * Fetch the info about the currently-executing (or most recently completed)
     * workflow operation.
     */
    protected function getLatestWorkflow($site)
    {
        $workflows = $site->getWorkflows()->fetch(['paged' => false,])->all();
        $workflow = array_shift($workflows);
        $workflow->fetchWithLogs();
        return $workflow;
    }

    /**
     * Return the metadata for this build.
     *
     * @return string[]
     */
    public function getBuildMetadata($repositoryDir)
    {
        return [
          'url'         => exec("git -C $repositoryDir config --get remote.origin.url"),
          'ref'         => exec("git -C $repositoryDir rev-parse --abbrev-ref HEAD"),
          'sha'         => exec("git -C $repositoryDir rev-parse HEAD"),
          'comment'     => exec("git -C $repositoryDir log --pretty=format:%s -1"),
          'commit-date' => exec("git -C $repositoryDir show -s --format=%ci HEAD"),
          'build-date'  => date("Y-m-d H:i:s O"),
        ];
    }

    /**
     * Write the build metadata into the build results prior to committing them.
     *
     * @param string[] $metadata
     */
    public function recordBuildMetadata($metadata, $repositoryDir)
    {
        $buildMetadataFile = "$repositoryDir/build-metadata.json";
        $metadataContents = json_encode($metadata, JSON_UNESCAPED_SLASHES);
        $this->log()->notice('Set {file} to {metadata}.', ['metadata' => $metadataContents, 'file' => basename($buildMetadataFile)]);

        file_put_contents($buildMetadataFile, $metadataContents);
    }

    /**
     * Iterate through the different environments, and keep fetching their
     * metadata until we find one that has a 'url' component.
     *
     * @param string $site_id The site to operate on
     * @param stirng[] $oldestEnvironments List of environments
     * @return string
     */
    protected function retrieveRemoteUrlFromBuildMetadata($site_id, $oldestEnvironments)
    {
        foreach ($oldestEnvironments as $env) {
            try {
                $metadata = $this->retrieveBuildMetadata("{$site_id}.{$env}");
                if (isset($metadata['url'])) {
                    return $metadata['url'];
                }
            }
            catch(Exception $e) {
            }
        }
        return '';
    }

    /**
     * Get the build metadata from a remote site.
     *
     * @param string $site_env_id
     * @return string[]
     */
    public function retrieveBuildMetadata($site_env_id)
    {
        $src = ':code/build-metadata.json';
        $dest = '/tmp/build-metadata.json';

        $status = $this->rsync($site_env_id, $src, $dest);
        if ($status != 0) {
            return [];
        }

        $metadataContents = file_get_contents($dest);
        $metadata = json_decode($metadataContents, true);

        unlink($dest);

        return $metadata;
    }

    /**
     * Add the 'pantheon' remote if it hasn't already been added
     */
    public function addPantheonRemote($env, $repositoryDir)
    {
        if (!$this->hasPantheonRemote()) {
            $connectionInfo = $env->connectionInfo();
            $gitUrl = $connectionInfo['git_url'];
            $this->passthru("git -C $repositoryDir remote add pantheon $gitUrl");
        }
    }

    /**
     * Check to see if there is a remote named 'pantheon'
     */
    protected function hasPantheonRemote()
    {
        exec('git remote show', $output);
        return array_search('pantheon', $output) !== false;
    }

    /**
     * Substitute replacements in a string. Replacements should be formatted
     * as {key} for raw value, or [[key]] for shell-escaped values.
     *
     * @param string $message
     * @param string[] $context
     * @return string[]
     */
    private function interpolate($message, array $context)
    {
        // build a replacement array with braces around the context keys
        $replace = array();
        foreach ($context as $key => $val) {
            if (!is_array($val) && (!is_object($val) || method_exists($val, '__toString'))) {
                $replace[sprintf('{%s}', $key)] = $val;
                $replace[sprintf('[[%s]]', $key)] = ProcessUtils::escapeArgument($val);
            }
        }

        // interpolate replacement values into the message and return
        return strtr($message, $replace);
    }

    /**
     * Call rsync to or from the specified site.
     *
     * @param string $site_env_id Remote site
     * @param string $src Source path to copy from. Start with ":" for remote.
     * @param string $dest Destination path to copy to. Start with ":" for remote.
     */
    protected function rsync($site_env_id, $src, $dest)
    {
        list($site, $env) = $this->getSiteEnv($site_env_id);
        $env_id = $env->getName();

        $siteInfo = $site->serialize();
        $site_id = $siteInfo['id'];

        $siteAddress = "$env_id.$site_id@appserver.$env_id.$site_id.drush.in:";

        $src = preg_replace('/^:/', $siteAddress, $src);
        $dest = preg_replace('/^:/', $siteAddress, $dest);

        $this->log()->debug('Rsync {src} => {dest}', ['src' => $src, 'dest' => $dest]);
        passthru("rsync -rlIvz --ipv4 --exclude=.git -e 'ssh -p 2222' $src $dest >/dev/null 2>&1", $status);

        return $status;
    }

    /**
     * Return 'true' if the specified file was changed in the provided commit.
     */
    protected function commitChangesFile($commit, $file)
    {
        $outputLines = $this->exec("git show --name-only $commit $file");

        return !empty($outputLines);
    }

    /**
     * Call passthru; throw an exception on failure.
     *
     * @param string $command
     */
    protected function passthru($command)
    {
        $result = 0;
        $this->log()->debug("Running {cmd}", ['cmd' => $command]);
        passthru($command, $result);

        if ($result != 0) {
            throw new TerminusException('Command `{command}` failed with exit code {status}', ['command' => $command, 'status' => $result]);
        }
    }

    /**
     * Call exec; throw an exception on failure.
     *
     * @param string $command
     * @return string[]
     */
    protected function exec($command)
    {
        $result = 0;
        $this->log()->debug("Running {cmd}", ['cmd' => $command]);
        exec($command, $outputLines, $result);

        if ($result != 0) {
            throw new TerminusException('Command `{command}` failed with exit code {status}', ['command' => $command, 'status' => $result]);
        }
        return $outputLines;
    }

    // Create a temporary directory
    public function tempdir($prefix='php', $dir=FALSE)
    {
        $tempfile=tempnam($dir ? $dir : sys_get_temp_dir(), $prefix ? $prefix : '');
        if (file_exists($tempfile)) {
            unlink($tempfile);
        }
        mkdir($tempfile);
        chmod($tempfile, 0700);
        if (is_dir($tempfile)) {
            $this->tmpDirs[] = $tempfile;
            return $tempfile;
        }
    }

    // Delete our work directory on exit.
    public function cleanup()
    {
        if (empty($this->tmpDirs)) {
            return;
        }

        $fs = new Filesystem();
        $fs->remove($this->tmpDirs);
    }
}
