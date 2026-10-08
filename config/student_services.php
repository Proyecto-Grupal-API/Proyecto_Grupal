<?php

return [
    'consents' => [
        'terms' => ['version' => '2026.1', 'name' => 'Términos de uso', 'description' => 'Reglas de uso de Campus Virtual.', 'required' => true],
        'privacy' => ['version' => '2026.1', 'name' => 'Aviso de privacidad', 'description' => 'Tratamiento de datos personales y académicos.', 'required' => true],
        'marketing' => ['version' => null, 'name' => 'Comunicaciones institucionales', 'description' => 'Novedades, eventos y beneficios del campus.', 'required' => false],
        'profile_terms' => ['version' => '2026.1', 'name' => 'Términos del perfil estudiantil', 'description' => 'Uso de la funcionalidad de perfil estudiantil y sus datos de contacto. Esta aceptación es independiente de las credenciales.', 'required' => true],
        'credential_terms' => ['version' => '2026.1', 'name' => 'Términos de credenciales QR y NFC', 'description' => 'Uso personal de credenciales de identificación QR y NFC. Esta aceptación es independiente del perfil estudiantil.', 'required' => true],
    ],
];
