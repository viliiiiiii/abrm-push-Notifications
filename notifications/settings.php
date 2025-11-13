<?php
require_once __DIR__ . '/../helpers.php';

if (!is_logged_in()) {
    require_login();
}

redirect_with_message('/account/profile.php#notification-preferences', 'Notification settings now live on your profile page.', 'info');
