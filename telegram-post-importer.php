<?php
/**
 * Plugin Name: Telegram Post Importer
 * Description: Import posts from a public Telegram channel and create matching WordPress posts.
 * Version: 0.1.0
 * Author: Qiong Wu
 */

if (!defined('ABSPATH')) {
    exit;
}

const TPI_OPTION = 'tpi_settings';
const TPI_LAST_RESULT = 'tpi_last_import_result';

add_action('admin_menu', 'tpi_register_menu');
add_action('admin_init', 'tpi_register_settings');
add_action('admin_post_tpi_import', 'tpi_handle_import');
add_action('admin_post_tpi_preview', 'tpi_handle_preview');
add_action('admin_post_tpi_import_selected', 'tpi_handle_import_selected');

function tpi_register_menu() {
    add_options_page(
        'Telegram Post Importer',
        'Telegram Importer',
        'manage_options',
        'telegram-post-importer',
        'tpi_render_settings_page'
    );
}

function tpi_register_settings() {
    register_setting('tpi_settings_group', TPI_OPTION, [
        'sanitize_callback' => 'tpi_sanitize_settings',
    ]);
}

function tpi_sanitize_settings($settings) {
    $out = [];
    $out['channel'] = isset($settings['channel']) ? sanitize_text_field($settings['channel']) : '';
    $out['post_status'] = isset($settings['post_status']) ? sanitize_key($settings['post_status']) : 'draft';
    $out['author_id'] = isset($settings['author_id']) ? absint($settings['author_id']) : get_current_user_id();
    $out['max_per_run'] = isset($settings['max_per_run']) ? absint($settings['max_per_run']) : 50;
    $out['category_id'] = isset($settings['category_id']) ? absint($settings['category_id']) : 0;
    $out['overwrite_existing'] = !empty($settings['overwrite_existing']) ? 1 : 0;

    return $out;
}

function tpi_get_settings() {
    $defaults = [
        'channel' => '',
        'post_status' => 'draft',
        'author_id' => get_current_user_id(),
        'max_per_run' => 50,
        'category_id' => 0,
        'overwrite_existing' => 0,
    ];

    $settings = get_option(TPI_OPTION, []);
    if (!is_array($settings)) {
        $settings = [];
    }

    return array_merge($defaults, $settings);
}

function tpi_render_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $settings = tpi_get_settings();
    $last_result = get_transient(TPI_LAST_RESULT);

    if ($last_result) {
        delete_transient(TPI_LAST_RESULT);
    }
    ?>
    <div class="wrap">
        <h1>Telegram Post Importer</h1>
        <?php if ($last_result): ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo esc_html($last_result); ?></p>
            </div>
        <?php endif; ?>

        <form method="post" action="options.php">
            <?php settings_fields('tpi_settings_group'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="tpi_channel">Channel Username</label></th>
                    <td>
                        <input name="<?php echo esc_attr(TPI_OPTION); ?>[channel]" id="tpi_channel" type="text" value="<?php echo esc_attr($settings['channel']); ?>" class="regular-text" />
                        <p class="description">Example: <code>samplechannelname</code> (without https://t.me/)</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tpi_post_status">Post Status</label></th>
                    <td>
                        <select name="<?php echo esc_attr(TPI_OPTION); ?>[post_status]" id="tpi_post_status">
                            <?php foreach (['draft', 'publish', 'pending'] as $status): ?>
                                <option value="<?php echo esc_attr($status); ?>" <?php selected($settings['post_status'], $status); ?>><?php echo esc_html(ucfirst($status)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tpi_author_id">Author</label></th>
                    <td>
                        <?php
                        wp_dropdown_users([
                            'name' => esc_attr(TPI_OPTION) . '[author_id]',
                            'selected' => $settings['author_id'],
                            'show_option_none' => false,
                        ]);
                        ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tpi_category_id">Category</label></th>
                    <td>
                        <?php
                        wp_dropdown_categories([
                            'name' => esc_attr(TPI_OPTION) . '[category_id]',
                            'selected' => $settings['category_id'],
                            'show_option_none' => 'None',
                            'option_none_value' => 0,
                        ]);
                        ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tpi_max_per_run">Max Messages Per Import</label></th>
                    <td>
                        <input name="<?php echo esc_attr(TPI_OPTION); ?>[max_per_run]" id="tpi_max_per_run" type="number" min="0" value="<?php echo esc_attr($settings['max_per_run']); ?>" class="small-text" />
                        <p class="description">0 means import all (can time out on large channels).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Overwrite Existing</th>
                    <td>
                        <label for="tpi_overwrite_existing">
                            <input name="<?php echo esc_attr(TPI_OPTION); ?>[overwrite_existing]" id="tpi_overwrite_existing" type="checkbox" value="1" <?php checked(!empty($settings['overwrite_existing'])); ?> />
                            Update existing posts when the message was already imported.
                        </label>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save Settings'); ?>
        </form>

        <hr />

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('tpi_preview'); ?>
            <input type="hidden" name="action" value="tpi_preview" />
            <?php submit_button('Fetch Messages', 'secondary'); ?>
        </form>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('tpi_import'); ?>
            <input type="hidden" name="action" value="tpi_import" />
            <?php submit_button('Import All (no review)', 'secondary'); ?>
        </form>

        <?php tpi_render_preview_table($settings); ?>
    </div>
    <?php
}

function tpi_handle_preview() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized', 403);
    }

    check_admin_referer('tpi_preview');

    $settings = tpi_get_settings();
    if (empty($settings['channel'])) {
        tpi_set_result_and_redirect('Missing channel username.');
    }

    $channel = tpi_normalize_channel($settings['channel']);
    $settings['channel'] = $channel;
    $max_per_run = (int) $settings['max_per_run'];

    $messages = tpi_collect_messages($channel, $max_per_run);
    if ($messages === null) {
        tpi_set_result_and_redirect('Failed to fetch Telegram channel.');
    }

    if (empty($messages)) {
        tpi_set_result_and_redirect('No messages found.');
    }

    $cache_key = tpi_preview_cache_key();
    set_transient($cache_key, $messages, 10 * MINUTE_IN_SECONDS);
    tpi_set_result_and_redirect(sprintf('Fetched %d messages. Select which to import below.', count($messages)));
}

function tpi_handle_import_selected() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized', 403);
    }

    check_admin_referer('tpi_import_selected');

    $settings = tpi_get_settings();
    if (empty($settings['channel'])) {
        tpi_set_result_and_redirect('Missing channel username.');
    }

    $selected_ids = isset($_POST['tpi_message_ids']) ? array_map('absint', (array) $_POST['tpi_message_ids']) : [];
    $selected_ids = array_filter($selected_ids);
    if (empty($selected_ids)) {
        tpi_set_result_and_redirect('No messages selected.');
    }

    $cache_key = tpi_preview_cache_key();
    $messages = get_transient($cache_key);
    if (!is_array($messages)) {
        tpi_set_result_and_redirect('Preview expired. Fetch messages again.');
    }

    $settings['channel'] = tpi_normalize_channel($settings['channel']);
    $selected = [];
    foreach ($messages as $message) {
        if (in_array((int) $message['id'], $selected_ids, true)) {
            $selected[] = $message;
        }
    }

    if (empty($selected)) {
        tpi_set_result_and_redirect('No matching messages found.');
    }

    $imported = 0;
    $updated = 0;
    $skipped = 0;

    foreach ($selected as $message) {
        $existing_id = tpi_find_existing_post_id($settings['channel'], $message['id']);
        if ($existing_id && empty($settings['overwrite_existing'])) {
            $skipped++;
            continue;
        }

        $post_id = tpi_create_post_from_message($message, $settings, $existing_id);
        if ($post_id) {
            if ($existing_id) {
                $updated++;
            } else {
                $imported++;
            }
        }
    }

    if (!empty($settings['overwrite_existing'])) {
        tpi_set_result_and_redirect(sprintf('Imported %d posts, updated %d existing posts, skipped %d posts.', $imported, $updated, $skipped));
    }
    tpi_set_result_and_redirect(sprintf('Imported %d posts, skipped %d existing posts.', $imported, $skipped));
}

function tpi_handle_import() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized', 403);
    }

    check_admin_referer('tpi_import');

    $settings = tpi_get_settings();
    if (empty($settings['channel'])) {
        tpi_set_result_and_redirect('Missing channel username.');
    }

    $channel = tpi_normalize_channel($settings['channel']);
    $settings['channel'] = $channel;
    $max_per_run = (int) $settings['max_per_run'];

    $messages = tpi_collect_messages($channel, $max_per_run);
    if ($messages === null) {
        tpi_set_result_and_redirect('Failed to fetch Telegram channel.');
    }

    if (empty($messages)) {
        tpi_set_result_and_redirect('No messages found.');
    }

    $imported = 0;
    $updated = 0;
    $skipped = 0;

    foreach ($messages as $message) {
        $existing_id = tpi_find_existing_post_id($channel, $message['id']);
        if ($existing_id && empty($settings['overwrite_existing'])) {
            $skipped++;
            continue;
        }

        $post_id = tpi_create_post_from_message($message, $settings, $existing_id);
        if ($post_id) {
            if ($existing_id) {
                $updated++;
            } else {
                $imported++;
            }
        }
    }

    if (!empty($settings['overwrite_existing'])) {
        tpi_set_result_and_redirect(sprintf('Imported %d posts, updated %d existing posts, skipped %d posts.', $imported, $updated, $skipped));
    }
    tpi_set_result_and_redirect(sprintf('Imported %d posts, skipped %d existing posts.', $imported, $skipped));
}

function tpi_set_result_and_redirect($message) {
    set_transient(TPI_LAST_RESULT, $message, 30);
    wp_safe_redirect(admin_url('options-general.php?page=telegram-post-importer'));
    exit;
}

function tpi_normalize_channel($channel) {
    $channel = trim($channel);
    $channel = preg_replace('#^https?://t\.me/#', '', $channel);
    $channel = ltrim($channel, '@');

    return $channel;
}

function tpi_preview_cache_key() {
    $user_id = get_current_user_id();
    return 'tpi_preview_' . $user_id;
}

function tpi_render_preview_table($settings) {
    $cache_key = tpi_preview_cache_key();
    $messages = get_transient($cache_key);
    if (!is_array($messages) || empty($messages)) {
        return;
    }

    $sort = isset($_GET['tpi_sort']) ? sanitize_key($_GET['tpi_sort']) : 'date';
    $order = isset($_GET['tpi_order']) ? sanitize_key($_GET['tpi_order']) : 'desc';
    $order = in_array($order, ['asc', 'desc'], true) ? $order : 'desc';

    if ($sort === 'date') {
        usort($messages, function ($a, $b) use ($order) {
            $a_time = !empty($a['datetime']) ? strtotime($a['datetime']) : 0;
            $b_time = !empty($b['datetime']) ? strtotime($b['datetime']) : 0;
            if ($a_time === $b_time) {
                $cmp = $a['id'] <=> $b['id'];
            } else {
                $cmp = $a_time <=> $b_time;
            }
            return $order === 'asc' ? $cmp : -$cmp;
        });
    }

    $existing_map = tpi_find_existing_posts_map($settings['channel'], $messages);
    $sort_url = admin_url('options-general.php?page=telegram-post-importer');
    ?>
    <h2>Select Messages to Import</h2>
    <p>Preview expires in 10 minutes. Existing posts are unchecked unless overwrite is enabled.</p>
    <form method="get" action="<?php echo esc_url($sort_url); ?>">
        <input type="hidden" name="page" value="telegram-post-importer" />
        <label for="tpi_sort_order">Sort by date:</label>
        <select name="tpi_order" id="tpi_sort_order">
            <option value="desc" <?php selected($order, 'desc'); ?>>Newest first</option>
            <option value="asc" <?php selected($order, 'asc'); ?>>Oldest first</option>
        </select>
        <input type="hidden" name="tpi_sort" value="date" />
        <?php submit_button('Apply', 'secondary', '', false); ?>
    </form>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('tpi_import_selected'); ?>
        <input type="hidden" name="action" value="tpi_import_selected" />
        <p>
            <button type="button" class="button" data-tpi-toggle="all">Select all</button>
            <button type="button" class="button" data-tpi-toggle="none">Select none</button>
        </p>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th style="width: 30px;">Import</th>
                    <th style="width: 140px;">Date</th>
                    <th>Title</th>
                    <th>Message</th>
                    <th style="width: 120px;">Imported</th>
                    <th style="width: 120px;">Link</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($messages as $message): ?>
                    <?php
                    $message_id = (int) $message['id'];
                    $is_existing = isset($existing_map[$message_id]);
                    $title = !empty($message['title_text']) ? $message['title_text'] : '';
                    $title = wp_strip_all_tags($title);
                    $title = tpi_safe_substr($title, 0, 120);
                    $text_html = $message['text_html'];
                    if (!empty($message['title_text'])) {
                        $text_html = tpi_remove_title_from_text($text_html, $message['title_text']);
                    }
                    $excerpt = wp_strip_all_tags($text_html);
                    $excerpt = tpi_safe_substr($excerpt, 0, 140);
                    ?>
                    <tr>
                        <td>
                            <input type="checkbox" name="tpi_message_ids[]" value="<?php echo esc_attr($message_id); ?>" <?php checked(!$is_existing || !empty($settings['overwrite_existing'])); ?> />
                        </td>
                        <td><?php echo esc_html(tpi_format_datetime($message['datetime'])); ?></td>
                        <td>
                            <?php if (!empty($title)): ?>
                                <strong><?php echo esc_html($title); ?></strong>
                            <?php else: ?>
                                <em>No title</em>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($excerpt)): ?>
                                <?php echo esc_html($excerpt); ?>
                            <?php else: ?>
                                <em>No text</em>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($is_existing): ?>
                                <?php
                                $edit_link = get_edit_post_link($existing_map[$message_id]);
                                ?>
                                <?php if ($edit_link): ?>
                                    <a href="<?php echo esc_url($edit_link); ?>">Edit</a>
                                <?php else: ?>
                                    <em>Imported</em>
                                <?php endif; ?>
                            <?php else: ?>
                                <em>—</em>
                            <?php endif; ?>
                        </td>
                        <td><a href="<?php echo esc_url($message['link']); ?>" target="_blank" rel="noopener">View</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php submit_button('Import Selected', 'primary'); ?>
    </form>
    <script>
        (function() {
            var container = document.currentScript ? document.currentScript.parentNode : null;
            if (!container) {
                return;
            }
            var buttons = container.querySelectorAll('[data-tpi-toggle]');
            if (!buttons.length) {
                return;
            }
            var checkboxes = container.querySelectorAll('input[name="tpi_message_ids[]"]');
            buttons.forEach(function(button) {
                button.addEventListener('click', function() {
                    var mode = button.getAttribute('data-tpi-toggle');
                    var checked = mode === 'all';
                    checkboxes.forEach(function(box) {
                        box.checked = checked;
                    });
                });
            });
        })();
    </script>
    <?php
}

function tpi_find_existing_posts_map($channel, $messages) {
    $channel = tpi_normalize_channel($channel);
    $ids = array_map(function ($message) {
        return (int) $message['id'];
    }, $messages);
    $ids = array_filter($ids);
    if (empty($ids)) {
        return [];
    }

    $existing_posts = get_posts([
        'post_type' => 'post',
        'post_status' => 'any',
        'meta_query' => [
            [
                'key' => '_tpi_channel',
                'value' => $channel,
            ],
            [
                'key' => '_tpi_message_id',
                'value' => $ids,
                'compare' => 'IN',
            ],
        ],
        'fields' => 'ids',
        'posts_per_page' => -1,
    ]);

    if (empty($existing_posts)) {
        return [];
    }

    $existing_map = [];
    foreach ($existing_posts as $post_id) {
        $message_id = (int) get_post_meta($post_id, '_tpi_message_id', true);
        if ($message_id) {
            $existing_map[$message_id] = $post_id;
        }
    }

    return $existing_map;
}

function tpi_format_datetime($datetime) {
    if (empty($datetime)) {
        return '';
    }

    $timestamp = strtotime($datetime);
    if (!$timestamp) {
        return $datetime;
    }

    return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
}

function tpi_collect_messages($channel, $max_per_run) {
    $messages = [];
    $seen_ids = [];
    $before = null;
    $page_limit = 50;

    while ($page_limit > 0) {
        $page_limit--;
        $batch = tpi_fetch_messages_page($channel, $before);
        if ($batch === null) {
            return null;
        }

        if (empty($batch)) {
            break;
        }

        foreach ($batch as $message) {
            $message_id = isset($message['id']) ? (int) $message['id'] : 0;
            if ($message_id <= 0 || isset($seen_ids[$message_id])) {
                continue;
            }
            $seen_ids[$message_id] = true;
            $messages[] = $message;
            if ($max_per_run > 0 && count($messages) >= $max_per_run) {
                break 2;
            }
        }

        $last = end($batch);
        $before = $last ? $last['id'] : null;
    }

    return array_reverse($messages);
}

function tpi_fetch_messages_page($channel, $before) {
    $url = sprintf('https://t.me/s/%s', rawurlencode($channel));
    if ($before) {
        $url .= '?before=' . intval($before);
    }

    $response = wp_remote_get($url, [
        'timeout' => 20,
        'headers' => [
            'User-Agent' => 'WordPress Telegram Post Importer',
        ],
    ]);

    if (is_wp_error($response)) {
        return null;
    }

    $body = wp_remote_retrieve_body($response);
    if (empty($body)) {
        return null;
    }

    return tpi_parse_messages_html($body, $channel);
}

function tpi_parse_messages_html($html, $channel) {
    if (!class_exists('DOMDocument')) {
        return tpi_parse_messages_html_regex($html, $channel);
    }

    $html = tpi_force_utf8($html);
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    tpi_dom_load_html($dom, $html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $wraps = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' tgme_widget_message_wrap ')]");
    if (!$wraps || $wraps->length === 0) {
        return [];
    }

    $messages = [];

    foreach ($wraps as $wrap) {
        $message_node = $xpath->query(".//div[contains(concat(' ', normalize-space(@class), ' '), ' tgme_widget_message ')]", $wrap)->item(0);
        if (!$message_node) {
            continue;
        }

        $data_post = $message_node->getAttribute('data-post');
        if (!$data_post) {
            continue;
        }

        $parts = explode('/', $data_post);
        $message_id = isset($parts[1]) ? (int) $parts[1] : 0;
        if ($message_id <= 0) {
            continue;
        }

        $text_node = $xpath->query(".//div[contains(concat(' ', normalize-space(@class), ' '), ' tgme_widget_message_text ')]", $wrap)->item(0);
        $text_html = $text_node ? tpi_inner_html($text_node) : '';
        $text_html = tpi_strip_emoji_backgrounds($text_html);
        $title_text = tpi_extract_title_from_text($text_html);

        $media_html = tpi_extract_media_html($wrap, $xpath);

        $time_node = $xpath->query('.//time', $wrap)->item(0);
        $datetime = $time_node ? $time_node->getAttribute('datetime') : '';

        $messages[] = [
            'id' => $message_id,
            'link' => sprintf('https://t.me/%s/%d', $channel, $message_id),
            'text_html' => $text_html,
            'title_text' => $title_text,
            'media_html' => $media_html,
            'datetime' => $datetime,
        ];
    }

    return $messages;
}

function tpi_parse_messages_html_regex($html, $channel) {
    $messages = [];
    $chunks = preg_split('/<div class="tgme_widget_message_wrap[^"]*">/i', $html);
    if (!$chunks || count($chunks) < 2) {
        return [];
    }

    array_shift($chunks);
    foreach ($chunks as $chunk) {
        if (!preg_match('/data-post="([^"]+)"/i', $chunk, $post_match)) {
            continue;
        }

        $parts = explode('/', $post_match[1]);
        $message_id = isset($parts[1]) ? (int) $parts[1] : 0;
        if ($message_id <= 0) {
            continue;
        }

        $text_html = '';
        if (preg_match('/<div class="tgme_widget_message_text[^"]*">(.*?)<\\/div>/is', $chunk, $text_match)) {
            $text_html = $text_match[1];
        }
        $text_html = tpi_strip_emoji_backgrounds($text_html);

        $media_html = tpi_extract_media_html_regex($chunk);

        $datetime = '';
        if (preg_match('/<time[^>]+datetime="([^"]+)"/i', $chunk, $time_match)) {
            $datetime = $time_match[1];
        }

        $messages[] = [
            'id' => $message_id,
            'link' => sprintf('https://t.me/%s/%d', $channel, $message_id),
            'text_html' => $text_html,
            'title_text' => tpi_extract_title_from_text($text_html),
            'media_html' => $media_html,
            'datetime' => $datetime,
        ];
    }

    return $messages;
}

function tpi_inner_html($node) {
    $html = '';
    if (!$node || !isset($node->childNodes)) {
        return $html;
    }
    foreach ($node->childNodes as $child) {
        $html .= $node->ownerDocument->saveHTML($child);
    }

    return $html;
}

function tpi_extract_media_html($wrap, $xpath) {
    $html_parts = [];
    if (!$wrap || !$xpath) {
        return '';
    }

    $photo_nodes = $xpath->query(".//a[contains(concat(' ', normalize-space(@class), ' '), ' tgme_widget_message_photo_wrap ')]", $wrap);
    if ($photo_nodes && $photo_nodes->length > 0) {
        foreach ($photo_nodes as $photo_node) {
            $style = $photo_node->getAttribute('style');
            if (!$style) {
                continue;
            }
            if (preg_match('/url\([\"\']?([^\"\')]+)[\"\']?\)/', $style, $matches)) {
                $url = esc_url_raw($matches[1]);
                if ($url) {
                    $html_parts[] = sprintf('<p><img src="%s" alt="" /></p>', esc_url($url));
                }
            }
        }
    }

    $video_nodes = $xpath->query('.//video', $wrap);
    if ($video_nodes && $video_nodes->length > 0) {
        foreach ($video_nodes as $video_node) {
            $src = $video_node->getAttribute('src');
            if (!$src) {
                $source_node = $video_node->getElementsByTagName('source')->item(0);
                $src = $source_node ? $source_node->getAttribute('src') : '';
            }
            $src = esc_url_raw($src);
            if ($src) {
                $html_parts[] = sprintf('<p><video controls src="%s"></video></p>', esc_url($src));
            }
        }
    }

    $file_nodes = $xpath->query(".//a[contains(concat(' ', normalize-space(@class), ' '), ' tgme_widget_message_document ')]", $wrap);
    if ($file_nodes && $file_nodes->length > 0) {
        foreach ($file_nodes as $file_node) {
            $href = $file_node->getAttribute('href');
            $title = trim($file_node->textContent);
            $href = esc_url_raw($href);
            if ($href) {
                $label = $title ? esc_html($title) : 'Download file';
                $html_parts[] = sprintf('<p><a href="%s">%s</a></p>', esc_url($href), $label);
            }
        }
    }

    return implode("\n", $html_parts);
}

function tpi_extract_media_html_regex($chunk) {
    $html_parts = [];

    if (preg_match_all('/tgme_widget_message_photo_wrap[^>]+style="[^"]*url\\([\\\'\\"]?([^\\\'\\")]+)[\\\'\\"]?\\)/i', $chunk, $photo_matches)) {
        foreach ($photo_matches[1] as $url) {
            $url = esc_url_raw($url);
            if ($url) {
                $html_parts[] = sprintf('<p><img src="%s" alt="" /></p>', esc_url($url));
            }
        }
    }

    if (preg_match_all('/<video[^>]+src="([^"]+)"/i', $chunk, $video_matches)) {
        foreach ($video_matches[1] as $url) {
            $url = esc_url_raw($url);
            if ($url) {
                $html_parts[] = sprintf('<p><video controls src="%s"></video></p>', esc_url($url));
            }
        }
    } elseif (preg_match_all('/<source[^>]+src="([^"]+)"/i', $chunk, $source_matches)) {
        foreach ($source_matches[1] as $url) {
            $url = esc_url_raw($url);
            if ($url) {
                $html_parts[] = sprintf('<p><video controls src="%s"></video></p>', esc_url($url));
            }
        }
    }

    if (preg_match_all('/<a[^>]+class="[^"]*tgme_widget_message_document[^"]*"[^>]+href="([^"]+)"[^>]*>(.*?)<\\/a>/is', $chunk, $doc_matches)) {
        foreach ($doc_matches[1] as $idx => $href) {
            $title = isset($doc_matches[2][$idx]) ? trim(strip_tags($doc_matches[2][$idx])) : '';
            $href = esc_url_raw($href);
            if ($href) {
                $label = $title ? esc_html($title) : 'Download file';
                $html_parts[] = sprintf('<p><a href="%s">%s</a></p>', esc_url($href), $label);
            }
        }
    }

    return implode("\n", $html_parts);
}

function tpi_message_exists($channel, $message_id) {
    $posts = get_posts([
        'post_type' => 'post',
        'post_status' => 'any',
        'meta_query' => [
            [
                'key' => '_tpi_channel',
                'value' => $channel,
            ],
            [
                'key' => '_tpi_message_id',
                'value' => $message_id,
            ],
        ],
        'fields' => 'ids',
        'posts_per_page' => 1,
    ]);

    return !empty($posts);
}

function tpi_find_existing_post_id($channel, $message_id) {
    $posts = get_posts([
        'post_type' => 'post',
        'post_status' => 'any',
        'meta_query' => [
            [
                'key' => '_tpi_channel',
                'value' => $channel,
            ],
            [
                'key' => '_tpi_message_id',
                'value' => $message_id,
            ],
        ],
        'fields' => 'ids',
        'posts_per_page' => 1,
    ]);

    if (empty($posts)) {
        return 0;
    }

    return (int) $posts[0];
}

function tpi_create_post_from_message($message, $settings, $existing_id = 0) {
    $content_parts = [];
    if (!empty($message['text_html'])) {
        $text_html = $message['text_html'];
        if (!empty($message['title_text'])) {
            $text_html = tpi_remove_title_from_text($text_html, $message['title_text']);
        }
        $content_parts[] = $text_html;
    }
    if (!empty($message['media_html'])) {
        $content_parts[] = $message['media_html'];
    }

    $content = wp_kses_post(implode("\n\n", $content_parts));
    $title_source = !empty($message['title_text']) ? $message['title_text'] : $message['text_html'];
    $title = wp_strip_all_tags($title_source);
    if (empty($title)) {
        $title = 'Telegram post #' . $message['id'];
    }
    $title = tpi_safe_substr($title, 0, 80);

    $post_data = [
        'post_title' => $title,
        'post_content' => $content,
        'post_status' => $settings['post_status'],
        'post_author' => $settings['author_id'],
        'post_type' => 'post',
    ];

    if ($existing_id) {
        $post_data['ID'] = (int) $existing_id;
    }

    if (!empty($settings['category_id'])) {
        $post_data['post_category'] = [(int) $settings['category_id']];
    }

    if (!empty($message['datetime'])) {
        $timestamp = strtotime($message['datetime']);
        if ($timestamp) {
            $post_data['post_date_gmt'] = gmdate('Y-m-d H:i:s', $timestamp);
            $post_data['post_date'] = get_date_from_gmt($post_data['post_date_gmt']);
        }
    }

    $post_id = $existing_id ? wp_update_post($post_data, true) : wp_insert_post($post_data, true);
    if (is_wp_error($post_id)) {
        return 0;
    }

    update_post_meta($post_id, '_tpi_channel', $settings['channel']);
    update_post_meta($post_id, '_tpi_message_id', $message['id']);
    update_post_meta($post_id, '_tpi_message_link', $message['link']);

    return $post_id;
}

function tpi_force_utf8($value) {
    $value = (string) $value;
    if ($value === '') {
        return $value;
    }

    if (function_exists('mb_detect_encoding')) {
        $encoding = mb_detect_encoding($value, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true);
        if ($encoding && $encoding !== 'UTF-8') {
            $value = mb_convert_encoding($value, 'UTF-8', $encoding);
        }
    } elseif (function_exists('iconv')) {
        $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $value);
        if ($converted !== false) {
            $value = $converted;
        }
    }

    return $value;
}

function tpi_dom_load_html($dom, $html) {
    $html = (string) $html;
    if (stripos($html, '<meta charset=') === false) {
        $html = '<?xml encoding="UTF-8">' . $html;
    }

    $dom->loadHTML($html);
}

function tpi_remove_title_from_text($text_html, $title_text) {
    $text_html = (string) $text_html;
    $title_text = trim((string) $title_text);
    if ($title_text === '' || $text_html === '') {
        return $text_html;
    }

    if (class_exists('DOMDocument')) {
        $text_html = tpi_force_utf8($text_html);
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        tpi_dom_load_html($dom, '<div>' . $text_html . '</div>');
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);
        $bold = $xpath->query('//strong|//b');
        if ($bold && $bold->length > 0) {
            $node = $bold->item(0);
            if ($node && trim($node->textContent) === $title_text) {
                $node->parentNode->removeChild($node);
            }
        }

        $wrapper = $dom->getElementsByTagName('div')->item(0);
        if ($wrapper) {
            return tpi_inner_html($wrapper);
        }
    }

    $escaped = preg_quote($title_text, '/');
    $text_html = preg_replace('/^\\s*<\\s*(strong|b)[^>]*>\\s*' . $escaped . '\\s*<\\s*\\/\\s*(strong|b)\\s*>\\s*(<br\\s*\\/?\\s*>\\s*)?/i', '', $text_html, 1);
    $text_html = preg_replace('/^\\s*' . $escaped . '\\s*(<br\\s*\\/?\\s*>\\s*)?/i', '', $text_html, 1);

    return $text_html;
}

function tpi_extract_title_from_text($text_html) {
    $text_html = trim((string) $text_html);
    if ($text_html === '') {
        return '';
    }

    if (!class_exists('DOMDocument')) {
        return trim(strtok(wp_strip_all_tags($text_html), "\n"));
    }

    $text_html = tpi_force_utf8($text_html);
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    tpi_dom_load_html($dom, '<div>' . $text_html . '</div>');
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $bold = $xpath->query('//strong|//b');
    if ($bold && $bold->length > 0) {
        $candidate = trim($bold->item(0)->textContent);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    $candidate = trim(strtok(wp_strip_all_tags($text_html), "\n"));
    return $candidate;
}

function tpi_strip_emoji_backgrounds($text_html) {
    $text_html = (string) $text_html;
    if ($text_html === '') {
        return $text_html;
    }

    if (class_exists('DOMDocument')) {
        $text_html = tpi_force_utf8($text_html);
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        tpi_dom_load_html($dom, '<div>' . $text_html . '</div>');
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//i[contains(concat(" ", normalize-space(@class), " "), " emoji ")]');
        if ($nodes && $nodes->length > 0) {
            foreach ($nodes as $node) {
                $style = $node->getAttribute('style');
                if ($style && stripos($style, 'telegram.org/img/emoji') !== false) {
                    $node->removeAttribute('style');
                }
            }
        }
        $wrapper = $dom->getElementsByTagName('div')->item(0);
        if ($wrapper) {
            return tpi_inner_html($wrapper);
        }
    }

    $pattern = '/(<i\\b[^>]*class="[^"]*\\bemoji\\b[^"]*"[^>]*?)\\sstyle=(["\'])[^\\"\']*telegram\\.org\\/img\\/emoji[^\\"\']*\\2([^>]*>)/i';
    return preg_replace($pattern, '$1$3', $text_html);
}

function tpi_safe_substr($value, $start, $length) {
    if (function_exists('mb_substr')) {
        return mb_substr($value, $start, $length);
    }

    return substr($value, $start, $length);
}
