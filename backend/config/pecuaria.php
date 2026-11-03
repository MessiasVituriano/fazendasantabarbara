<?php

/*
|--------------------------------------------------------------------------
| Regras de negócio da pecuária
|--------------------------------------------------------------------------
|
| Todos os prazos usados nas automações (projeções reprodutivas e desmame)
| são lidos daqui. Nunca use env() fora deste arquivo: com config:cache o
| .env deixa de ser lido em tempo de execução.
|
*/

return [
    'dias_gestacao' => (int) env('PECUARIA_DIAS_GESTACAO', 283),
    'dias_para_toque' => (int) env('PECUARIA_DIAS_PARA_TOQUE', 35),
    'dias_ante_parto_secagem' => (int) env('PECUARIA_DIAS_ANTE_PARTO_SECAGEM', 60),
    'dias_desmame_bezerro' => (int) env('PECUARIA_DIAS_DESMAME_BEZERRO', 210),

    // Quantos dias para trás o caseiro pode lançar/corrigir dados (o gestor não tem limite).
    'dias_retroativos_caseiro' => (int) env('PECUARIA_DIAS_RETROATIVOS_CASEIRO', 3),

    // Fotos dos animais: disco do config/filesystems.php ('public' local, 's3' em produção).
    'fotos' => [
        'disco' => env('PECUARIA_DISCO_FOTOS', 'public'),
        'lado_maximo' => 1600,   // px do maior lado da imagem armazenada
        'miniatura' => 320,      // px da miniatura quadrada (avatar e cartões)
        'tamanho_maximo_kb' => 10240,
    ],

    // Financeiro: comprovantes ficam em disco privado ('local' ou 's3'), baixados só com login.
    'financeiro' => [
        'disco_anexos' => env('PECUARIA_DISCO_ANEXOS', 'local'),
        'tamanho_maximo_anexo_kb' => 10240,
        'maximo_parcelas' => 120,
    ],
];
