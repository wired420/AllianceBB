<?php

declare(strict_types=1);

use AllianceBB\App;
use AllianceBB\Core\Context;

/*
 * A plugin's entry file returns a function. It receives the App, which gives
 * access to hooks, the router, the view and every core service.
 */
return function (App $app): void {
    $hooks = $app->hooks();
    $db = $app->db();

    // 1. Add a field to the account form.
    $hooks->addFilter('render.account_form', function (string $html, array $user) use ($db): string {
        $current = (string) $db->value('SELECT signature FROM plugin_signatures WHERE user_id = ?', [$user['id']]);
        return $html . '<label>Signature <textarea name="signature" rows="2" maxlength="300">'
            . htmlspecialchars($current) . '</textarea><small class="muted">Shown under your posts. Plain text, 300 characters.</small></label>';
    });

    // 2. Save it when the account form is saved.
    $hooks->addAction('account.saved', function (Context $ctx) use ($db): void {
        $signature = mb_substr(trim($ctx->request->input('signature')), 0, 300);
        if ($signature === '') {
            $db->run('DELETE FROM plugin_signatures WHERE user_id = ?', [$ctx->userId()]);
        } else {
            $db->run(
                'INSERT INTO plugin_signatures (user_id, signature) VALUES (?, ?)
                 ON CONFLICT (user_id) DO UPDATE SET signature = EXCLUDED.signature',
                [$ctx->userId(), $signature],
            );
        }
    });

    // 3. Show it after every post.
    $hooks->addFilter('render.post.after', function (string $html, array $post) use ($db): string {
        if (empty($post['author_user_id'])) {
            return $html;
        }
        $signature = $db->value('SELECT signature FROM plugin_signatures WHERE user_id = ?', [$post['author_user_id']]);
        if (!$signature) {
            return $html;
        }
        return $html . '<div class="signature muted small" style="border-top:1px dashed var(--abb-border);margin-top:12px;padding-top:6px">'
            . nl2br(htmlspecialchars((string) $signature)) . '</div>';
    });
};
