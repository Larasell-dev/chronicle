<?php

return [
    'enabled' => env('CHRONICLE_ENABLED', true),

    /*
     * The Laravel log channel used by Chronicle. The package registers a
     * custom `chronicle` driver; define a `chronicle` channel in your app's
     * config/logging.php to override path, driver, or stack it elsewhere.
     */
    'channel' => env('CHRONICLE_CHANNEL', 'chronicle'),
];
