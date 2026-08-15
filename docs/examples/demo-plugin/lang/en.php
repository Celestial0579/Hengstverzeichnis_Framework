<?php
// docs/examples/demo-plugin/lang/en.php

return [
    'detail_heading' => '👋 Demo Plugin',
    'detail_text' => 'This section was added by the demo plugin via the horse.detail_sections hook, without changing a single core file.',
    'detail_station' => 'Publicly visible breeding station per the hook contract: {station} ({email}).',
    'edit_heading' => '👋 Demo plugin: section in the horse admin form',
    'edit_text' => 'This section comes from the horse.edit_sections hook and already knows the horse from its calling context: #{id} ({name}). A real addon would put its own form with its own POST route here - the Save button above does not save this section.',
];
