<?php
/** Public GitHub stable releases through WordPress's native Update URI API. */
final class CLI_GitHub_Updater {
    const REPO = 'https://github.com/CalicoDesignCo/calico-lead-intelligence';
    const API = 'https://api.github.com/repos/CalicoDesignCo/calico-lead-intelligence/releases/latest';
    const SLUG = 'calico-lead-intelligence';
    const CACHE = 'cli_public_release_v1';
    private static $plugin;

    public static function register( $file ) {
        self::$plugin = plugin_basename( $file );
        add_filter( 'update_plugins_github.com', array( __CLASS__, 'update' ), 10, 4 );
        add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
        add_action( 'load-update-core.php', array( __CLASS__, 'refresh' ) );
        add_action( 'load-plugins.php', array( __CLASS__, 'refresh' ) );
    }

    public static function refresh() {
        if ( isset( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) {
            delete_site_transient( self::CACHE );
        }
    }

    /** Parse only a normal, complete, version-pinned release with our exact asset. */
    public static function parse( $release ) {
        if ( ! is_array( $release ) || ! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) || empty( $release['id'] ) || ! preg_match( '/^v?(\d+\.\d+\.\d+)$/D', $release['tag_name'] ?? '', $matches ) ) {
            return false;
        }
        $tag = $release['tag_name'];
        $package = self::REPO . '/releases/download/' . $tag . '/' . self::SLUG . '.zip';
        foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
            if ( ! is_array( $asset ) || self::SLUG . '.zip' !== ( $asset['name'] ?? '' ) || 'uploaded' !== ( $asset['state'] ?? '' ) || empty( $asset['size'] ) || $package !== ( $asset['browser_download_url'] ?? '' ) ) { continue; }
            return array( 'version' => $matches[1], 'package' => $package, 'tag' => $tag );
        }
        return false;
    }

    public static function release() {
        $cached = get_site_transient( self::CACHE );
        if ( is_array( $cached ) && array_key_exists( 'release', $cached ) ) { return $cached['release']; }
        $response = wp_safe_remote_get( self::API, array(
            'timeout' => 8, 'redirection' => 2, 'sslverify' => true, 'limit_response_size' => 524288,
            'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'Calico-Lead-Intelligence-Updater' ),
        ) );
        $release = false;
        if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
            $release = self::parse( json_decode( wp_remote_retrieve_body( $response ), true ) );
        }
        // Short negative cache avoids repeated API failures without interrupting site use.
        set_site_transient( self::CACHE, array( 'release' => $release ), $release ? 21600 : 900 );
        return $release;
    }

    public static function update( $update, $data, $file, $locales ) {
        if ( self::$plugin !== $file ) { return $update; }
        $release = self::release();
        if ( ! $release || ! version_compare( $release['version'], $data['Version'] ?? '0', '>' ) ) { return false; }
        return array(
            'id' => self::REPO, 'slug' => self::SLUG, 'version' => $release['version'],
            'url' => self::REPO . '/releases/tag/' . $release['tag'], 'package' => $release['package'],
            'requires' => '6.0', 'requires_php' => '7.4',
        );
    }

    public static function details( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || self::SLUG !== ( $args->slug ?? '' ) ) { return $result; }
        $release = self::release();
        if ( ! $release ) { return $result; }
        return (object) array(
            'name' => 'Calico Lead Intelligence', 'slug' => self::SLUG, 'version' => $release['version'],
            'author' => 'Calico Design', 'homepage' => self::REPO, 'requires' => '6.0', 'requires_php' => '7.4',
            'download_link' => $release['package'],
            'sections' => array(
                'description' => 'Lead attribution, campaign reporting and customer journeys for Gravity Forms.',
                'changelog' => '<p>Read the versioned release notes on <a href="' . esc_url( self::REPO . '/releases/tag/' . $release['tag'] ) . '">GitHub</a>.</p>',
            ),
        );
    }
}
