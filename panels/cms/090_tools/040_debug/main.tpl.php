<?php
/**
 * The browsers seen in the last half hour on this project, the newest first.
 * "Debug window" opens a window of their own for one of them: from its next
 * request on, its debug output shows there instead of in its page.
 *
 * @var \Systopic\Relay\Panels\Root\Cms\Tools\Debug\Panel $this
 */

use Systopic\Relay\RemoteDebug;
use Systopic\System\Settings\CentrifugoHub;

$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES);
$clients = $this->clients();
$own = (string) ($_COOKIE[RemoteDebug::COOKIE] ?? '');
$ago = static function (int $seen): string {
	$seconds = max(0, time() - $seen);
	return $seconds < 60 ? $seconds . ' s' : intdiv($seconds, 60) . ' min';
};
$envHref = \http::$root . 'cms/settings/environment/';
$node = $this;
while ($node->parent !== null && $node->name !== 'cms') {
	$node = $node->parent;
}
$env = $node->name === 'cms' ? $node->child('settings')?->child('environment') : null;
if ($env !== null) {
	$envHref = $env->getHref(\http::$root);
}
?>
<h1>Remote debug</h1>
<p class="tagsHint">
	Browsers seen in the last <?= intdiv(RemoteDebug::LIST_SECONDS, 60) ?> minutes. The debug window shows the debug output of one of them
	live - from its next request on, its own page only says "debug → remote". Closing the window ends that.
</p>
<?php if (!CentrifugoHub::status()['running']) { ?>
	<div class="cms message is-stay"><div class="warning"><p>The hub is not running, so a debug window gets no live output. Open <a class="ajax" href="<?= $e($envHref) ?>">Environment</a>.</p></div></div>
<?php } ?>
<section class="widget is-list">
	<header>
		<h2>Browsers</h2>
		<a class="button" data-on_click="refreshList" title="Refresh"><i class="fa-regular fa-arrows-rotate"></i><span>Refresh</span></a>
	</header>
	<?php if ($clients === []): ?>
		<main><p>No browser seen yet.</p></main>
	<?php else: ?>
		<div class="tableScroll">
			<table>
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
							<td><?= $client['user'] === null ? '–' : (int) $client['user'] ?></td>
							<td><?= $e($client['url']) ?></td>
							<td><?= $e($ago((int) $client['seen'])) ?></td>
							<td class="icons">
								<ul>
									<li>
										<a class="button" target="_blank" rel="noopener" href="<?= $e($this->windowHref($client['id'])) ?>">
											<i class="fa-regular fa-arrow-up-right-from-square"></i><span><?= $client['session'] === null ? 'Debug window' : 'Watched – new window' ?></span>
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
