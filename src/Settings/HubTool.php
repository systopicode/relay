<?php

declare(strict_types=1);

namespace Systopic\Relay\Settings;

use Systopic\System\Auth\Session;
use Systopic\System\Panels\PanelNode;
use Systopic\System\Settings\CentrifugoHub;
use Systopic\System\Settings\EnvironmentFile;
use Systopic\System\Settings\SettingsTool;
use Systopic\System\Settings\ToolView;

/**
 * packages.relay (`x-tool` in machine.schema.json): the connection of this
 * environment to the shared hub (Centrifugo), started or stopped here, and
 * the browser addresses the hub has to allow.
 */
final class HubTool implements SettingsTool
{
    public static function actions(): array
    {
        return ['connectHub', 'startRelay', 'stopRelay', 'addHubOrigin', 'removeHubOrigin'];
    }

    public static function render(ToolView $view): void
    {
        if ($view->project) {
            return;
        }
        $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
        $file = $view->file;
        $relay = EnvironmentFile::effective($file, 'relay');
        $ready = ($relay['api'] ?? '') !== '' && ($relay['apiKey'] ?? '') !== '' && strlen((string) ($relay['secret'] ?? '')) >= 16;
        $status = CentrifugoHub::status();
        $enabled = $ready && ($relay['enabled'] ?? true) !== false;
        echo '<section class="cmsBox envHub"><header><div class="cmsBoxTitles"><h2>Centrifugo</h2><p class="cmsBoxLead">Live updates and online status for this project.</p></div></header><div class="cmsBoxBody">';
        echo '<div class="envHubStatus"><div><strong>Project connection</strong><span>' . (!$ready ? 'Not configured' : ($enabled ? 'Enabled' : 'Stopped')) . '</span></div>';
        if ($view->here) {
            echo '<div><strong>Shared hub</strong><span>' . ($status['running'] ? 'Running' : 'Not reachable') . '</span></div>';
        }
        echo '</div>';
        if ($view->here && Session::isSuperuser()) {
            echo '<div class="cmsBoxActions is-start">';
            if ($ready) {
                echo '<button type="button" class="button" data-on_click="' . ($enabled ? 'stopRelay' : 'startRelay') . '" data-file="' . $e($file) . '"><i class="cmsIcon ' . ($enabled ? 'cmsIcon-stop' : 'cmsIcon-play') . '"></i>' . ($enabled ? 'Stop live updates' : 'Start live updates') . '</button>';
            } elseif ($status['configured']) {
                echo '<button type="button" class="button" data-on_click="userConfirm" data-action="connectHub" data-confirm="Copy the local hub key into this environment?" data-file="' . $e($file) . '"><i class="cmsIcon cmsIcon-key-skeleton"></i>Connect project</button>';
            }
            echo '</div>';
        }
        echo '<p class="cmsBoxLead">Start/Stop affects this project and environment from the next request. The shared server and other projects keep running. Keys stay hidden.</p>';
        if ($view->here) {
            self::origins($file, $view->data);
        }
        echo '</div></section>';
    }

    public static function handle(PanelNode $panel, string $action, string $file): void
    {
        if (!Session::isSuperuser()) {
            \message::error('Only a superuser can change the hub.');
            return;
        }
        if (!ToolView::running($file)) {
            \message::error('That file is not the one this server is using.');
            return;
        }
        if (in_array($action, ['addHubOrigin', 'removeHubOrigin'], true)) {
            $origin = (string) \http::dataset('origin');
            $add = $action === 'addHubOrigin';
            $data = EnvironmentFile::read($file);
            $permitted = $add ? CentrifugoHub::projectOrigins($data['matches'] ?? [], self::scheme()) : CentrifugoHub::ownedOrigins($file);
            if (!in_array($origin, $permitted, true) || !CentrifugoHub::changeOrigin($file, $origin, $add)) {
                \message::error('The origin permission was not saved. Check the address and hub config permissions.');
            } else {
                \message::confirm('Hub config saved. A server administrator must restart the shared hub to apply origin changes. No server was restarted.');
            }
            $panel->updateView();
            return;
        }
        if (in_array($action, ['startRelay', 'stopRelay'], true)) {
            $enabled = $action === 'startRelay';
            EnvironmentFile::enableRelay($file, $enabled)
                ? \message::confirm($enabled ? 'Live updates started for this environment. Other open windows follow on their next request.' : 'Live updates stopped for this environment. Other open windows follow on their next request.')
                : \message::error('Live updates could not be changed.');
            $panel->updateView();
            return;
        }
        $block = CentrifugoHub::relayBlock(!str_starts_with($file, 'LIVE'));
        if ($block === null || !EnvironmentFile::connectRelay($file, $block)) {
            \message::error('The hub key was not saved.');
            return;
        }
        \message::confirm('Hub key saved. Live status starts on the next request.');
        $panel->updateView();
    }

    private static function scheme(): string
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    }

    /** @param array<string, mixed> $data */
    private static function origins(string $file, array $data): void
    {
        $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
        $required = CentrifugoHub::projectOrigins(is_array($data['matches'] ?? null) ? $data['matches'] : [], self::scheme());
        $owned = CentrifugoHub::ownedOrigins($file);
        $origins = array_values(array_unique([...$required, ...$owned]));
        $allowed = CentrifugoHub::allowedOrigins();
        $invalidIp = false;
        foreach ($data['matches'] ?? [] as $rule) {
            foreach (is_array($rule) ? (array) ($rule['serverIp'] ?? []) : [] as $ip) {
                if (trim((string) $ip) !== '' && !filter_var(trim((string) $ip), FILTER_VALIDATE_IP)) {
                    $invalidIp = true;
                }
            }
        }
        echo '<div class="envHubOrigins"><div class="envHubAddressHeader"><h3>Browser addresses</h3>';
        if (Session::isSuperuser()) {
            echo '<button type="button" class="button" data-on_click="addAddress" data-file="' . $e($file) . '"><i class="cmsIcon cmsIcon-plus"></i>Add domain / IP</button>';
        }
        echo '</div><p class="cmsBoxLead">Browser origins must be allowed by the shared live-update server. The addresses below come from “Where this config applies”. Shared permissions already exist in the hub configuration; newly added permissions belong to this project.</p>';
        if ($invalidIp) {
            echo '<p class="envHubInvalid">An IP address below is invalid. Correct the marked field before adding its hub permission.</p>';
        }
        echo '<section class="widget is-list envHubTable"><div class="tableScroll"><table class="envOrigins"><thead><tr><th>Browser address</th><th>Permission</th><th></th></tr></thead><tbody>';
        foreach ($origins as $origin) {
            $known = $allowed === null ? null : CentrifugoHub::originAllowed($origin, $allowed);
            $canRemove = in_array($origin, $owned, true);
            echo '<tr><td><code>' . $e($origin) . '</code></td><td><small>' . ($known === null ? 'Hub config unavailable' : ($known ? ($canRemove ? 'Added for this project' : 'Shared permission') : 'Missing in saved hub config')) . '</small></td><td class="icons">';
            if (Session::isSuperuser() && $allowed !== null) {
                if (!$known && in_array($origin, $required, true)) {
                    echo '<button type="button" class="button" data-on_click="userConfirm" data-action="addHubOrigin" data-file="' . $e($file) . '" data-origin="' . $e($origin) . '" data-confirm="Add this browser origin to the shared hub config? Applying it requires an administrator to restart the hub." title="Add permission"><i class="cmsIcon cmsIcon-plus"></i>Add</button>';
                } elseif ($canRemove) {
                    echo '<button type="button" class="button transparent" data-on_click="userConfirm" data-action="removeHubOrigin" data-file="' . $e($file) . '" data-origin="' . $e($origin) . '" data-confirm="Remove this project’s origin permission? A permission still registered by another project is kept. Otherwise all uses of this managed origin end after the administrator restarts the hub." title="Remove project permission"><i class="cmsIcon cmsIcon-link-slash"></i></button>';
                }
            }
            echo '</td></tr>';
        }
        if ($origins === []) {
            echo '<tr><td colspan="3">No browser addresses configured yet.</td></tr>';
        }
        echo '</tbody></table></div></section><p class="cmsBoxLead envHubRestart"><i class="cmsIcon cmsIcon-circle-info"></i>Permissions shown are the saved configuration. Origin changes need an administrator to restart the shared hub; these controls never restart it.</p></div>';
    }
}
