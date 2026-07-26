<?php
/**
 * GitHub-based update integration.
 *
 * Uses the Plugin Update Checker library (YahnisElsts/plugin-update-checker)
 * so that WordPress can detect, notify about and install new versions
 * published as GitHub Releases — right from the Plugins page.
 *
 * Release workflow (see README.md):
 *   1. Bump the Version header in the main plugin file.
 *   2. Commit, then publish a GitHub Release tagged with that version
 *      (e.g. "v1.1.0" — a leading "v" is fine).
 *   3. Attach the build artifact "social-proof-for-hivepress.zip" as a
 *      release asset (see build.sh / the release workflow).
 *
 * @package Social_Proof_For_HivePress
 */

defined( 'ABSPATH' ) || exit;

class HPSP_Updater {

	/**
	 * Public repository URL.
	 */
	const REPO_URL = 'https://github.com/irapidchris-del/social-proof-for-hivepress/';

	/**
	 * Stable branch. With "main"/"master", the checker prefers the latest
	 * GitHub Release, then the highest tag, then the branch head.
	 */
	const BRANCH = 'main';

	/**
	 * Matches the release asset to download. Accepts both the clean
	 * "social-proof-for-hivepress.zip" and an optionally version-tagged
	 * "social-proof-for-hivepress-1.2.3.zip"; either extracts to the correct
	 * "social-proof-for-hivepress" folder.
	 */
	const ASSET_REGEX = '/social-proof-for-hivepress(-[0-9.]+)?\.zip$/i';

	/**
	 * The built update checker instance.
	 *
	 * @var object|null
	 */
	protected static $checker = null;

	/**
	 * Wire up the update checker.
	 */
	public static function init() : void {
		$loader = HPSP_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';

		// Fail safe: never break the plugin if the library is missing.
		if ( ! is_readable( $loader ) ) {
			return;
		}

		require_once $loader;

		$factory = '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory';

		if ( ! class_exists( $factory ) ) {
			return;
		}

		self::$checker = call_user_func(
			[ $factory, 'buildUpdateChecker' ],
			self::REPO_URL,
			HPSP_FILE,
			'social-proof-for-hivepress'
		);

		if ( ! is_object( self::$checker ) ) {
			return;
		}

		// Prefer GitHub Releases; read the version from the release tag.
		if ( method_exists( self::$checker, 'setBranch' ) ) {
			self::$checker->setBranch( self::BRANCH );
		}

		// Download the attached release .zip asset (clean folder name) rather
		// than GitHub's auto-generated source archive, which would install to
		// a commit/version-suffixed folder and trigger "plugin folder" warnings.
		if ( method_exists( self::$checker, 'getVcsApi' ) ) {
			$api = self::$checker->getVcsApi();

			if ( is_object( $api ) && method_exists( $api, 'enableReleaseAssets' ) ) {
				$api->enableReleaseAssets( self::ASSET_REGEX );
			}
		}

		/**
		 * Fires after the update checker is configured, passing the instance so
		 * integrators can add authentication (e.g. a token for a private repo)
		 * or adjust the check period.
		 *
		 * @param object $checker Plugin Update Checker instance.
		 */
		do_action( 'hpsp_update_checker', self::$checker );
	}
}
