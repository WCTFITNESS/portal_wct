<?php

declare(strict_types=1);

$app['portalAuthService']->logout();
redirect_to('index.php?page=login');
