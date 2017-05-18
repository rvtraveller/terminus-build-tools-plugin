<?php

namespace Pantheon\TerminusBuildTools\CIProviders;

use Pantheon\Terminus\Exceptions\TerminusException;

class GitlabCIProvider extends CIProvider {

  public $provider = 'gitlabci';

  /**
   * {@inheritdoc}
   */
  public function getToken() {
    // There is nothing to do here because Gitlab CI is all built in.

    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function getBadge($target_project) {
    return "";
  }

  /**
   * {@inheritdoc}
   */
  public function prepare($site, $options, $terminus_token, $git_token) {
    return [
      'ADMIN_EMAIL' => $options['admin-email'],
      'account-name' => 'mgadmin',
      'ADMIN_PASSWORD' => $options['admin-password'],
      'TEST_SITE_NAME' => $site->getName(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function configure($target_project, $ci_token, $ci_env, $current_session) {
    return;
  }
}