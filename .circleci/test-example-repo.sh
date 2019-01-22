#!/bin/bash

# This was `set -ex`, but removed echo to avoid leaking $BITBUCKET_PASS
# TODO: We should also pass the $GITHUB_TOKEN when cloning the GitHub repo so that it can be a private repo if desired.
set -e

TERMINUS_SITE=build-tools-$CIRCLE_BUILD_NUM

# If we are on the master branch
if [[ $CIRCLE_BRANCH == "master" ]]
then
    PR_BRANCH="dev-master"
else
    # If this is a pull request use the PR number
    if [[ -z "$CIRCLE_PULL_REQUEST" ]]
    then
        # Stash PR number
        PR_NUMBER=${CIRCLE_PULL_REQUEST##*/}

        # Multidev name is the pull request number
        PR_BRANCH="pr-$PR_NUMBER"
    else
        # Otherwise use the build number
        PR_BRANCH="dev-$CIRCLE_BUILD_NUM"
    fi
fi

SOURCE_COMPOSER_PROJECT="$1"
TARGET_REPO_WORKING_COPY=$HOME/$TERMINUS_SITE
GIT_PROVIDER="$2"


# If we are on the 1.x branch set the build tools version to 1.x
if [[ $CIRCLE_BRANCH == "1.x" ]]
then
    BUILD_TOOLS_VERSION="${CIRCLE_BRANCH}#${CIRCLE_SHA1}"
# Otherwise use the current branch
else
    # If on root repo use the current branch
    if [[ $CIRCLE_PROJECT_USERNAME == "pantheon-systems" ]]; then
        BUILD_TOOLS_VERSION="dev-${CIRCLE_BRANCH}#${CIRCLE_SHA1}"
    # Otherwise use the dev tip from the pantheon-systems repo
    else
        BUILD_TOOLS_VERSION="dev-master"
    fi
fi

if [ "$GIT_PROVIDER" == "github" ]; then
    TARGET_REPO=$GITHUB_USER/$TERMINUS_SITE
    CLONE_URL="https://github.com/${TARGET_REPO}.git"
    CI_PROVIDER="circle"
else
    if [ "$GIT_PROVIDER" == "bitbucket" ]; then
        TARGET_REPO=$BITBUCKET_USER/$TERMINUS_SITE
        CLONE_URL="https://$BITBUCKET_USER@bitbucket.org/${TARGET_REPO}.git"
        CI_PROVIDER="circle"
    else
        if [ "$GIT_PROVIDER" == "gitlab" ]; then
            TARGET_REPO=$GITLAB_USER/$TERMINUS_SITE
            CLONE_URL="https://$GITLAB_USER@gitlab.com/${TARGET_REPO}.git"
            CI_PROVIDER="gitlabci"
        else
            echo "Unsupported GIT_PROVIDER. Valid values are: github, bitbucket, gitlab"
            exit 1
        fi
    fi
fi

terminus build:project:create -n "$SOURCE_COMPOSER_PROJECT" "$TERMINUS_SITE" --git=$GIT_PROVIDER --ci=$CI_PROVIDER --team="$TERMINUS_ORG" --email="$GIT_EMAIL" --env="BUILD_TOOLS_VERSION=$BUILD_TOOLS_VERSION"
# Confirm that the Pantheon site was created
terminus site:info "$TERMINUS_SITE"
# Confirm that the Github or Bitbucket project was created
if [ ["$GIT_PROVIDER" == "github"] || ["$GIT_PROVIDER" == "bitbucket"] ]
    git clone "$CLONE_URL" "$TARGET_REPO_WORKING_COPY"
fi
# Confirm that Circle was configured for testing, and that the first test passed.
if [ "$CI_PROVIDER" == "circle" ]
    (
        set +ex
        cd "$TARGET_REPO_WORKING_COPY" && circle token "$CIRCLE_TOKEN" && circle watch
    )
fi

# Delete our test site, etc.
./.circleci/cleanup-fixtures.sh
