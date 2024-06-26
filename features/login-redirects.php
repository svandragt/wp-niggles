<?php

namespace Niggles\LoginRedirects;

function bootstrap()
{
    add_action('wp_login', __NAMESPACE__ . '\\redirect_by_role', 10,2);
}

function redirect_by_role($user_login, $user) : void
{
    if ($user->has_cap('customize')) {
        // Admins to dashboard
        return;
    }
    if ($user->has_cap('delete_others_pages')) {
        // Editors to page list
        wp_safe_redirect(admin_url( 'edit.php?post_type=page' ));
        die();
    }
    if ($user->has_cap('publish_posts')) {
        // Authors to new page
        wp_safe_redirect(admin_url( 'post-new.php?post_type=post' ));
        die();
    }
    if ($user->has_cap('delete_posts')) {
        // Contributors to new post
        wp_safe_redirect(admin_url( 'post-new.php?post_type=post' ));
        die();
    }
}
