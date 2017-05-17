<?php

namespace Pantheon\TerminusBuildTools\CodeProviders;

/**
 * Build tools integration with Gitlab.
 */
class GitlabProvider extends GitProvider {

  /**
   * {@inheritdoc}
   */
  public $provider = 'gitlab';

  /**
   * {@inheritdoc}
   */
  public function getToken() {
    // Ask for a GitHub token if one is not available.
    $gitlab_token = getenv('GITLAB_TOKEN');

    while (empty($gitlab_token)) {
      $gitlab_token = $this->io()->askHidden("Please generate a Gitlab personal access token. \n\n Enter it here:");
      $gitlab_token = trim($gitlab_token);
      putenv("GITLAB_TOKEN=$gitlab_token");

      // Validate that the GitHub token looks correct. If not, prompt again.
      if ((strlen($gitlab_token) < 20) || preg_match('#[^0-9a-zA-Z\-]#', $gitlab_token)) {
        $this->log()->warning('Gitlab tokens should be 20-character strings containing only the letters a-f and digits (0-9). Please enter your token again.');
        $gitlab_token = '';
      }
    }

    return $gitlab_token;
  }

  /**
   * {@inheritdoc}
   */
  public function create($source, $target, $git_org, $git_token, $stability) {
    // We need a different URL here if $git_org is an org; if no
    // org is provided, then we use a simpler URL to create a repository
    // owned by the currently-authenitcated user.
    $target_org = $git_org;
    $createRepoUrl = "api/v4/projects";
    $target_project = "$target_org/$target";

    $source_project = $this->sourceProjectFromSource($source);
    $tmpsitedir = $this->tempdir('local-site');

    $local_site_path = "$tmpsitedir/$target";

    $this->log()->notice('Creating project and resolving dependencies.');

    // If the source is 'org/project:dev-branch', then automatically
    // set the stability to 'dev'.
    if (empty($stability) && preg_match('#:dev-#', $source)) {
      $stability = 'dev';
    }
    // Pass in --stability to `composer create-project` if user requested it.
    $stability_flag = empty($stability) ? '' : "--stability $stability";

    // TODO: Do we need to remove $local_site_path/.git? (-n should obviate this need)
    $this->passthru("composer create-project $source $local_site_path -n $stability_flag");

    // Create a Gitlab repository
    $this->log()->notice('Creating repository {repo} from {source}', ['repo' => $target_project, 'source' => $source]);
    $postData = [
      'name' => $target,
      'namespace_id' => 141,
    ];
    $result = $this->curl($createRepoUrl, $postData, $git_token);

    // Create a git repository. Add an origin just to have the data there
    // when collecting the build metadata later. We use the 'pantheon'
    // remote when pushing.
    $this->passthru("git -C $local_site_path init");
    $this->passthru("git -C $local_site_path remote add origin 'git@git.mindgrub.net:{$target_project}.git'");

    return [$target_project, $local_site_path];
  }

  /**
   * {@inheritdoc}
   */
  public function push($git_token, $target_project, $repositoryDir) {
    $this->log()->notice('Push initial commit to Gitlab');
    $remote_url = "https://oauth2:$git_token@git.mindgrub.net/${target_project}.git";
    $this->passthruRedacted("git -C $repositoryDir push --progress $remote_url master", $git_token);
  }

  /**
   * {@inheritdoc}
   */
  public function site($target_project) {
    return "https://git.mindgrub.net/" . $target_project;
  }

  /**
   * {@inheritdoc}
   */
  public function desiredURL($target_project) {
    return "git@git.mindgrub.net:{$target_project}.git";
  }

  /**
   * {@inheritdoc}
   */
  public function delete($target_project, $git_token) {
    $ch = $this->createGitHubDeleteChannel("repos/$target_project", $git_token);
    $data = $this->execCurlRequest($ch, 'Gitlab');
  }

  public function preserveEnvsWithOpenPRs($remoteUrl, $oldestEnvironments, $multidev_delete_pattern, $auth = '') {
    $project = $this->projectFromRemoteUrl($remoteUrl);
    $branchList = $this->branchesForOpenPullRequests($project, $auth);
    return $this->filterBranches($oldestEnvironments, $branchList, $multidev_delete_pattern);
  }

  protected function branchesForOpenPullRequests($project, $auth = '') {
    $data = $this->curl("repos/$project/pulls?state=open", [], $auth);

    $branchList = array_map(
      function ($item) {
        return $item['head']['ref'];
      },
      $data
    );

    return $branchList;
  }

  protected function createGitHubDeleteChannel($uri, $auth) {
    $ch = $this->createGitlabCurlChannel($uri, $auth);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');

    return $ch;
  }

  protected function curl($uri, $postData = [], $auth = '') {
    $this->log()->notice('Call Gitlab API: {uri}', ['uri' => $uri]);
    $ch = $this->createGitlabPostChannel($uri, $postData, $auth);
    return $this->execCurlRequest($ch, 'Gitlab');
  }

  protected function createGitlabCurlChannel($uri, $auth = '')
  {
    $url = "https://git.mindgrub.net/$uri";
    return $this->createGitlabAuthorizationHeaderCurlChannel($url, $auth);
  }

  protected function createGitlabPostChannel($uri, $postData = [], $auth = '')
  {
    $ch = $this->createGitlabCurlChannel($uri, $auth);
    $this->setCurlChannelPostData($ch, $postData);

    return $ch;
  }

  protected function createGitlabAuthorizationHeaderCurlChannel($url, $auth = '')
  {
    $headers = [
      'Content-Type: application/json',
      'User-Agent: pantheon/terminus-build-tools-plugin'
    ];

    if (!empty($auth)) {
      $headers[] = "PRIVATE-TOKEN: $auth";
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    return $ch;
  }
}