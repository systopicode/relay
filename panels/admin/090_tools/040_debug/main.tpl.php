<?php
/**
 * The browsers seen in the last half hour on this project, the newest first.
 * "Debug window" opens a window of their own for one of them: from its next
 * request on, its debug output shows there instead of in its page.
 *
 * @var \Systopic\Relay\Panels\Root\Admin\Tools\Debug\Panel $this
 */

use Systopic\Relay\RemoteDebug;
use Systopic\System\Settings\CentrifugoHub;

$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES);
$clients = $this->clients();
$userLabels = [];
foreach ($clients as $client) {
    $id = (int) ($client['user'] ?? 0);
    if (!$id || isset($userLabels[$id])) continue;
    $user = \Systopic\System\Queries\UserAdmin\UserAdmin::of(\Systopic\Db\Scope::default())->user($id);
    $userLabels[$id] = $user ? (trim((string) $user->fullname) ?: (trim((string) $user->name) ?: (trim((string) $user->email) ?: (string) $id))) : (string) $id;
}
$own = (string) ($_COOKIE[RemoteDebug::COOKIE] ?? '');
$ago = static function (int $seen): string {
	$seconds = max(0, time() - $seen);
	return $seconds < 60 ? $seconds . ' s' : intdiv($seconds, 60) . ' min';
};
$envHref = \http::$root . 'admin/settings/config/?package=relay';
$node = $this;
while ($node->parent !== null && $node->name !== 'admin') {
	$node = $node->parent;
}
$env = $node->name === 'admin' ? $node->child('settings')?->child('config') : null;
if ($env !== null) {
	$envHref = $env->getHref(\http::$root) . '?package=relay';
}
?>
<h1>Remote debug</h1>
<?php if (!RemoteDebug::available()) { ?>
<div class="cms message is-stay"><div class="warning"><p>Remote debugging is currently unavailable. Enable project live updates and PHP debugging in <a href="<?= $e($envHref) ?>">Relay configuration</a> to use it.</p></div></div>
<?php return; } ?>
<p class="tagsHint">
	Browsers seen in the last <?= intdiv(RemoteDebug::LIST_SECONDS, 60) ?> minutes. The debug window shows the debug output of one of them
	live - from its next request on, its own page only says "debug → remote". Closing the window ends that.
</p>
<?php if (!CentrifugoHub::status()['running']) { ?>
	<div class="cms message is-stay"><div class="warning"><p>The hub is not running, so a debug window gets no live output. Open <a class="ajax" href="<?= $e($envHref) ?>">Relay configuration</a>.</p></div></div>
<?php } ?>
<section class="widget is-list">
	<header>
		<h2>Browsers</h2>
		<a class="button" data-on_click="refreshList" title="Refresh"><i class="cmsIcon cmsIcon-arrows-rotate"></i><span>Refresh</span></a>
	</header>
	<?php if ($clients === []): ?>
		<main><p>No browser seen yet.</p></main>
	<?php else: ?>
		<div class="tableScroll">
			<table class="remoteDebugClients">
				<thead>
					<tr><th>Device</th><th>IP</th><th>User</th><th>Last request</th><th>Seen</th><th></th></tr>
				</thead>
				<tbody>
					<?php foreach ($clients as $client): ?>
						<tr>
							<td title="<?= $e($client['ua']) ?>">
								<?= $e(RemoteDebug::label((string) $client['ua'])) ?>
								<?= $client['id'] === $own ? ' <em>(this browser)</em>' : '' ?>
							</td>
							<td><?= $e($client['ip']) ?></td>
							<td><?= $client['user'] === null ? '–' : $e($userLabels[(int) $client['user']] ?? $client['user']) ?></td>
							<td><?= $e($client['url']) ?></td>
							<td><?= $e($ago((int) $client['seen'])) ?></td>
							<td class="icons">
								<ul>
									<li>
										<a class="button icononly" title="Open remote debugging" aria-label="Open remote debugging" target="_blank" rel="noopener" href="<?= $e($this->windowHref($client['id'])) ?>">
											<i class="cmsIcon cmsIcon-laptop"></i>
										</a>
									</li>
								</ul>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>
