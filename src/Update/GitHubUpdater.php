<?php

declare(strict_types=1);

namespace CoaVault\Update;

/**
 * Self-hosted plugin updates straight from GitHub Releases. COA Vault is not on the
 * WordPress.org directory, so this hooks WordPress's native update machinery and
 * points it at https://github.com/{owner}/{repo}/releases. Every site then sees and
 * installs new versions from Dashboard → Updates exactly like any other plugin.
 *
 * Flow per release:
 *   1. Tag `vX.Y.Z` on GitHub → the release workflow builds and attaches `coa-vault.zip`.
 *   2. Each site's twice-daily update check calls the GitHub API (cached 6h here),
 *      compares the release tag against the installed version, and offers the update.
 *   3. WordPress downloads the release asset (or the source zipball as a fallback) and
 *      installs it; fix_source_dir() guarantees the folder lands as `coa-vault`.
 *
 * Only the public Releases API is used (no token), so a public repo needs no secrets.
 */
final class GitHubUpdater
{
    private const API = 'https://api.github.com/repos/%s/%s/releases/latest';
    // The released copy of a file, by tag — raw.githubusercontent.com, so it doesn't spend
    // the 60-requests-an-hour unauthenticated API quota the release lookup above uses.
    private const RAW = 'https://raw.githubusercontent.com/%s/%s/%s/%s';
    private const TTL = 6 * HOUR_IN_SECONDS;

    private string $plugin_file;
    private string $basename;  // coa-vault/coa-vault.php
    private string $slug;      // coa-vault
    private string $owner;
    private string $repo;
    private string $version;   // installed version, no leading "v"
    private string $cache_key;

    public function __construct(string $plugin_file, string $version, string $owner, string $repo)
    {
        $this->plugin_file = $plugin_file;
        $this->basename    = plugin_basename($plugin_file);
        $dir               = dirname($this->basename);
        $this->slug        = $dir !== '.' ? $dir : basename($this->basename, '.php');
        $this->version     = ltrim($version, 'vV');
        $this->owner       = $owner;
        $this->repo        = $repo;
        $this->cache_key   = 'coa_vault_update_' . md5($owner . '/' . $repo);
    }

    public function register(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_update']);
        add_filter('plugins_api', [$this, 'details'], 10, 3);
        add_filter('upgrader_source_selection', [$this, 'fix_source_dir'], 10, 4);
        add_action('upgrader_process_complete', [$this, 'flush_cache'], 10, 2);
    }

    /** Plugin icon URLs (served from the installed plugin) for the update list + details modal. */
    private function icons(): array
    {
        return [
            'svg'     => plugins_url('assets/icon.svg', $this->plugin_file),
            '1x'      => plugins_url('assets/icon-128x128.png', $this->plugin_file),
            '2x'      => plugins_url('assets/icon-256x256.png', $this->plugin_file),
            'default' => plugins_url('assets/icon-256x256.png', $this->plugin_file),
        ];
    }

    /**
     * Advertise an available update in the plugins update transient when GitHub's
     * latest release is newer than what's installed.
     *
     * @param mixed $transient
     * @return mixed
     */
    public function inject_update($transient)
    {
        if (!is_object($transient)) {
            return $transient;
        }

        $release = $this->get_release();
        if ($release === null) {
            return $transient;
        }

        $payload = (object) [
            'slug'         => $this->slug,
            'plugin'       => $this->basename,
            'new_version'  => $release['version'],
            'url'          => $release['html_url'],
            'package'      => $release['package'],
            'icons'        => $this->icons(),
            'banners'      => [],
            'tested'       => self::tested_for_running_wp($release['tested']),
            'requires'     => $release['requires'],
            'requires_php' => $release['requires_php'],
        ];

        if (version_compare($release['version'], $this->version, '>')) {
            $transient->response[$this->basename] = $payload;
        } else {
            // Newer than-or-equal: list under no_update so the UI stays tidy.
            $transient->no_update[$this->basename] = $payload;
        }

        return $transient;
    }

    /**
     * Populate the "View details" modal (plugins_api → plugin_information).
     *
     * @param mixed  $result
     * @param string $action
     * @param object $args
     * @return mixed
     */
    public function details($result, $action, $args)
    {
        if ($action !== 'plugin_information' || !isset($args->slug) || $args->slug !== $this->slug) {
            return $result;
        }

        $release = $this->get_release();
        if ($release === null) {
            return $result;
        }

        return (object) [
            'name'          => 'COA Vault',
            'slug'          => $this->slug,
            'version'       => $release['version'],
            'author'        => '<a href="https://beenacle.com">Beenacle</a>',
            'homepage'      => $release['html_url'],
            'download_link' => $release['package'],
            'icons'         => $this->icons(),
            'requires'      => $release['requires'],
            'requires_php'  => $release['requires_php'],
            'tested'        => self::tested_for_running_wp($release['tested']),
            'last_updated'  => $release['date'],
            'sections'      => [
                'description' => 'Certificate of Analysis (COA) management for WooCommerce — custom-table storage, simple + variable product support, multi-COA per size/variation, REST API, and a block/shortcode/auto-inject frontend.',
                'changelog'   => $release['changelog'],
            ],
        ];
    }

    /**
     * GitHub's source zipball extracts to "{repo}-{tag}/"; a built release asset
     * extracts to "coa-vault/". Either way, force the installed folder to the plugin
     * slug so WordPress doesn't orphan the update under a renamed directory.
     *
     * @param string $source
     * @param string $remote_source
     * @param object $upgrader
     * @param array  $args
     * @return string|\WP_Error
     */
    public function fix_source_dir($source, $remote_source, $upgrader, $args = [])
    {
        if (!isset($args['plugin']) || $args['plugin'] !== $this->basename) {
            return $source;
        }

        global $wp_filesystem;
        $desired = trailingslashit($remote_source) . $this->slug;

        if (untrailingslashit($source) === $desired) {
            return $source;
        }
        if ($wp_filesystem instanceof \WP_Filesystem_Base && $wp_filesystem->move($source, $desired, true)) {
            return trailingslashit($desired);
        }

        return $source;
    }

    /**
     * Drop our cached release after any plugin update so the next check is fresh.
     *
     * @param object $upgrader
     * @param array  $extra
     */
    public function flush_cache($upgrader, $extra): void
    {
        if (is_array($extra) && ($extra['type'] ?? '') === 'plugin') {
            delete_transient($this->cache_key);
        }
    }

    /**
     * Latest release, normalized and cached. Returns null when there is no usable
     * release (network error, rate limit, no asset) — callers then do nothing.
     *
     * @return array{version:string,package:string,html_url:string,date:string,changelog:string,requires:string,requires_php:string,tested:string}|null
     */
    private function get_release(): ?array
    {
        $cached = get_transient($this->cache_key);
        if (is_array($cached)) {
            return isset($cached['__error']) ? null : $cached;
        }

        $data = $this->fetch_release();
        if ($data === null) {
            // Short negative cache so a flaky API or rate limit doesn't hammer GitHub.
            set_transient($this->cache_key, ['__error' => true], 30 * MINUTE_IN_SECONDS);
            return null;
        }

        set_transient($this->cache_key, $data, self::TTL);
        return $data;
    }

    /** @return array<string,mixed>|null */
    private function fetch_release(): ?array
    {
        $url      = sprintf(self::API, rawurlencode($this->owner), rawurlencode($this->repo));
        $response = wp_remote_get($url, [
            'timeout' => 10,
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'COA-Vault-Updater/' . $this->version,
            ],
        ]);

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['tag_name'])) {
            return null;
        }

        // Prefer a built .zip asset (correct folder structure); fall back to the
        // auto-generated source zipball, whose folder fix_source_dir() normalizes.
        $package = '';
        foreach (($body['assets'] ?? []) as $asset) {
            if (!empty($asset['name']) && str_ends_with((string) $asset['name'], '.zip')) {
                $package = (string) $asset['browser_download_url'];
                break;
            }
        }
        if ($package === '') {
            $package = (string) ($body['zipball_url'] ?? '');
        }
        if ($package === '') {
            return null;
        }

        return [
            'version'      => ltrim((string) $body['tag_name'], 'vV'),
            'package'      => $package,
            'html_url'     => (string) ($body['html_url'] ?? ''),
            'date'         => (string) ($body['published_at'] ?? ''),
            'changelog'    => $this->render_changelog((string) ($body['body'] ?? '')),
        ] + $this->release_headers((string) $body['tag_name']);
    }

    /**
     * The release's OWN "Requires at least" / "Requires PHP" / "Tested up to", read from
     * the main plugin file at the release tag.
     *
     * These used to come from the INSTALLED copy, which describes the wrong version:
     * WordPress uses an update's requires_php to block updating on a too-old PHP and its
     * requires to block a too-old WordPress, so a release that raised either would have
     * been offered (and auto-updated) onto sites it breaks; and its tested drives the
     * "Compatibility with WordPress X" line. Any failure falls back to the installed
     * values, so a fetch problem never withholds an update.
     *
     * @return array{requires:string,requires_php:string,tested:string}
     */
    private function release_headers(string $tag): array
    {
        $out = [
            'requires'     => $this->plugin_header('requires', '6.4'),
            'requires_php' => $this->plugin_header('requires_php', '8.1'),
            'tested'       => $this->plugin_header('tested', '6.8'),
        ];

        $url      = sprintf(self::RAW, rawurlencode($this->owner), rawurlencode($this->repo), rawurlencode($tag), rawurlencode(basename($this->plugin_file)));
        $response = wp_remote_get($url, [
            'timeout' => 10,
            'headers' => ['User-Agent' => 'COA-Vault-Updater/' . $this->version],
        ]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return $out;
        }

        // Same header grammar and 8 KB window as core's get_file_data().
        $file = substr((string) wp_remote_retrieve_body($response), 0, 8 * KB_IN_BYTES);
        $labels = ['requires' => 'Requires at least', 'requires_php' => 'Requires PHP', 'tested' => 'Tested up to'];
        foreach ($labels as $key => $label) {
            if (preg_match('/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote($label, '/') . ':(.*)$/mi', $file, $m)) {
                $value = trim(_cleanup_header_comment($m[1]));
                if (preg_match('/^\d+(\.\d+)*$/', $value)) { // a version, or keep the fallback
                    $out[$key] = $value;
                }
            }
        }
        return $out;
    }

    /**
     * "Tested up to: 7.1" means the whole 7.1 branch, but WordPress compares it with
     * version_compare() against the FULL running version — so on 7.1.3 a bare "7.1"
     * reads "Not tested". WordPress.org expands the declared branch to its latest point
     * release server-side; a self-hosted updater has to do the same. Only the running
     * branch is widened: "7.1" on WordPress 7.2 still (correctly) reads "Not tested".
     */
    private static function tested_for_running_wp(string $tested): string
    {
        global $wp_version;
        $running = preg_replace('/-.*$/', '', (string) $wp_version) ?? ''; // core's own trim
        if ($tested === '' || $running === '') {
            return $tested;
        }
        $branch = static fn (string $v): string => implode('.', array_slice(explode('.', $v), 0, 2));
        if ($branch($tested) === $branch($running) && version_compare($tested, $running, '<')) {
            return $running;
        }
        return $tested;
    }

    /** Read a value from the installed plugin's file header (cached for the request). */
    private function plugin_header(string $key, string $default): string
    {
        static $headers = null;
        if ($headers === null) {
            $headers = get_file_data($this->plugin_file, [
                'requires'     => 'Requires at least',
                'requires_php' => 'Requires PHP',
                'tested'       => 'Tested up to',
            ]);
        }
        $value = isset($headers[$key]) ? trim((string) $headers[$key]) : '';
        return $value !== '' ? $value : $default;
    }

    /** Render a GitHub release body (Markdown) into safe HTML for the details modal. */
    private function render_changelog(string $markdown): string
    {
        $markdown = trim($markdown);
        if ($markdown === '') {
            return 'See the release notes on GitHub.';
        }

        $html = esc_html($markdown);
        $html = preg_replace('/^#{1,6}\s*(.+)$/m', '<h4>$1</h4>', $html) ?? $html;
        $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html) ?? $html;
        $html = preg_replace('/^\s*[-*]\s+(.+)$/m', '<li>$1</li>', $html) ?? $html;
        // Line-scoped (no /s): a greedy dot-all span would swallow everything
        // between the first and last <li> of the document — including headings
        // separating two lists — into one <ul>.
        $html = preg_replace('/(?:<li>[^\n]*<\/li>\n?)+/', '<ul>$0</ul>', $html) ?? $html;

        return wpautop($html);
    }
}
