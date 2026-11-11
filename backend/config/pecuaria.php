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
    // Nome do produto (tela de login, título da aba e menu). O nome da fazenda vem do cadastro dela.
    'produto' => env('PECUARIA_PRODUTO', 'Gestão Agropecuária'),

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

    // Estoque: itens com validade até N dias à frente aparecem como "vence em breve".
    'estoque' => [
        'dias_aviso_validade' => (int) env('PECUARIA_DIAS_AVISO_VALIDADE', 30),
    ],

    // Engorda: tendência de peso por regressão linear das pesagens recentes, projeção e metas do lote.
    'engorda' => [
        'janela_dias' => (int) env('PECUARIA_ENGORDA_JANELA_DIAS', 120),           // pesagens usadas na tendência
        'minimo_pesagens' => 3,
        'minimo_intervalo_dias' => 30,                                              // entre a 1ª e a última da janela
        'horizonte_dias' => 90,                                                     // além disso a reta deixa de valer
        'gmd_suspeito_min' => -1.0,                                                 // kg/dia entre pesagens seguidas
        'gmd_suspeito_max' => 3.0,
        'r2_confiavel' => 0.8,
        'r2_minimo' => 0.5,                                                         // abaixo disso não projeta
        'ritmo_ok' => (float) env('PECUARIA_ENGORDA_RITMO_OK', 0.95),               // GMD real / necessário
        'ritmo_atencao' => (float) env('PECUARIA_ENGORDA_RITMO_ATENCAO', 0.80),
        'gmd_maximo_plausivel' => (float) env('PECUARIA_ENGORDA_GMD_MAXIMO', 1.2),  // acima disso a meta é inviável
        'dias_sem_pesagem' => (int) env('PECUARIA_ENGORDA_DIAS_SEM_PESAGEM', 45),
        'peso_abate_kg' => (float) env('PECUARIA_PESO_ABATE_KG', 540),             // alvo padrão sem meta de lote
    ],
];
