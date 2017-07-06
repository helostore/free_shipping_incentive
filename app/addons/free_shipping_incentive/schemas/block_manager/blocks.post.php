<?php

if (!isset($schema['main']['cache_overrides_by_dispatch']['products.view']['disable_cache_when']['session_handlers'])) {
    $schema['main']['cache_overrides_by_dispatch']['products.view']['disable_cache_when']['session_handlers'] = array();
}
$schema['main']['cache_overrides_by_dispatch']['products.view']['disable_cache_when']['session_handlers']['cart.amount'] = array('gt', 0);

return $schema;