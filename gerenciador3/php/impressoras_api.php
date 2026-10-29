<?php
require_once __DIR__ . '/conexao.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

exigirAutenticacao(1);

function validarImpressora(array $data): array
{
    $fields = [
        'patrimonio',
        'modelo',
        'ip',
        'mac',
        'localizacao',
        'tipo'
    ];

    $result = [];

    foreach ($fields as $field) {
        $value = trim((string) ($data[$field] ?? ''));

        if ($value === '') {
            enviarJson([
                'success' => false,
                'message' => 'Preencha todos os campos obrigatórios.'
            ], 422);
        }

        $result[$field] = $value;
    }

    $result['disponivel'] = filter_var(
        $data['disponivel'] ?? true,
        FILTER_VALIDATE_BOOLEAN
    );

    return $result;
}

function validarIpPrivado(string $ip): bool
{
    if (filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_IPV4
    ) === false) {
        return false;
    }

    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) === false;
}

function inteiroSnmp($value): ?int
{
    if (is_int($value)) {
        return $value;
    }

    if (is_string($value) && preg_match('/-?\d+/', $value, $match)) {
        return (int) $match[0];
    }

    return null;
}

/**
 * Percorre uma coluna da Printer-MIB usando GETNEXT.
 *
 * A impressora Brother testada responde ao GETNEXT,
 * mas não responde corretamente ao snmp2_real_walk().
 */
function lerColunaSnmp(SNMP $snmp, string $coluna): array
{
    $base = '1.3.6.1.2.1.43.11.1.1.' . $coluna;
    $prefixo = $base . '.';
    $oid = $base;
    $resultado = [];

    for ($i = 0; $i < 200; $i++) {
        $resposta = $snmp->getnext([$oid]);

        if (!is_array($resposta) || !$resposta) {
            if ($i === 0) {
                throw new RuntimeException(
                    'Falha ao consultar a coluna SNMP '
                    . $coluna . ': ' . $snmp->getError()
                );
            }

            break;
        }

        $proximoOid = ltrim(
            (string) array_key_first($resposta),
            '.'
        );

        // O GETNEXT avançou para outra coluna.
        if (!str_starts_with($proximoOid, $prefixo)) {
            break;
        }

        // Evita ficar preso se a impressora repetir o OID.
        if ($proximoOid === $oid) {
            break;
        }

        $indice = substr($proximoOid, strlen($prefixo));
        $resultado[$indice] = reset($resposta);
        $oid = $proximoOid;
    }

    return $resultado;
}

/**
 * Brother reports toner remaining in its private brInfoMaintenance table
 * even on models that return -2 for toner in the standard Printer-MIB.
 * Each seven-byte record stores a resource code followed by its value in
 * hundredths of a percent.
 */
function lerTonerBrotherSnmp(SNMP $snmp): array
{
    $oid = '1.3.6.1.4.1.2435.2.3.9.4.2.1.5.5.8.0';
    set_error_handler(static fn (): bool => true);
    try {
        $value = $snmp->get($oid);
    } finally {
        restore_error_handler();
    }

    if (!is_string($value) || $value === '') {
        return [];
    }

    $plainHex = preg_replace('/0x/i', '', trim($value));
    if (preg_match('/^(?:[0-9a-f]{2}[\\s,:-]*)+$/i', $plainHex)) {
        $hex = preg_replace('/[^0-9a-f]/i', '', $plainHex);
    } else {
        $hex = bin2hex($value);
    }

    $codes = [
        '6f' => 'black',
        '70' => 'cyan',
        '71' => 'magenta',
        '72' => 'yellow',
        'a1' => 'black',
        'a2' => 'cyan',
        'a3' => 'magenta',
        'a4' => 'yellow'
    ];
    $levels = [];

    for ($offset = 0; $offset + 14 <= strlen($hex); $offset += 14) {
        $record = substr($hex, $offset, 14);
        $color = $codes[strtolower(substr($record, 0, 2))] ?? null;

        if ($color === null) {
            continue;
        }

        $hundredths = hexdec(substr($record, 6, 8));
        if ($hundredths > 0) {
            $levels[$color] = max(0, min(100, (int) round($hundredths / 100)));
        }
    }

    return $levels;
}

function consultarSuprimentos(string $ip, string $community): array
{
    if (!class_exists(SNMP::class)) {
        enviarJson([
            'success' => false,
            'message' => 'A extensão SNMP não está instalada. Verifique o container da aplicação.'
        ], 503);
    }

    if (!validarIpPrivado($ip)) {
        enviarJson([
            'success' => false,
            'message' => 'O IP cadastrado não é um endereço IPv4 privado válido.'
        ], 422);
    }

    if ($community === '' || strlen($community) > 128) {
        enviarJson([
            'success' => false,
            'message' => 'Configure uma comunidade SNMP válida no arquivo .env.'
        ], 503);
    }

    $snmp = null;

    try {
        $snmp = new SNMP(
            SNMP::VERSION_2C,
            $ip,
            $community,
            3000000,
            1
        );

        $snmp->oid_output_format = SNMP_OID_OUTPUT_NUMERIC;
        $snmp->valueretrieval = SNMP_VALUE_PLAIN;

        // Coluna 6: descrição do suprimento.
        $descriptions = lerColunaSnmp($snmp, '6');

        // Coluna 8: capacidade máxima.
        $capacities = lerColunaSnmp($snmp, '8');

        // Coluna 9: nível atual.
        $levels = lerColunaSnmp($snmp, '9');

        // Alguns modelos Brother deixam o nível do toner como -2 na
        // Printer-MIB, mas publicam o percentual no OID próprio da marca.
        $brotherTonerLevels = lerTonerBrotherSnmp($snmp);

        if (!$descriptions) {
            throw new RuntimeException(
                'A impressora não retornou descrições de suprimentos.'
            );
        }

        $supplies = [];

        foreach ($descriptions as $indice => $description) {
            $level = inteiroSnmp($levels[$indice] ?? null);
            $capacity = inteiroSnmp($capacities[$indice] ?? null);
            $descriptionText = trim((string) $description);
            $brotherColor = null;

            foreach (['black', 'cyan', 'magenta', 'yellow'] as $color) {
                if (stripos($descriptionText, $color) !== false) {
                    $brotherColor = $color;
                    break;
                }
            }

            /*
             * Valores negativos como -2 e -3 são códigos especiais
             * da Printer-MIB, não valores percentuais.
             */
            $percentage = (
                $level !== null
                && $capacity !== null
                && $level >= 0
                && $capacity > 0
            )
                ? max(
                    0,
                    min(
                        100,
                        (int) round(($level / $capacity) * 100)
                    )
                )
                : null;

            if (
                $percentage === null
                && $brotherColor !== null
                && array_key_exists($brotherColor, $brotherTonerLevels)
            ) {
                $percentage = $brotherTonerLevels[$brotherColor];
            }

            $supplies[] = [
                'description' => $descriptionText,
                'level' => $level,
                'max_capacity' => $capacity,
                'percentage' => $percentage
            ];
        }

        return $supplies;
    } catch (Throwable $error) {
        error_log(
            'Falha SNMP ao consultar impressora '
            . $ip . ': ' . $error->getMessage()
        );

        enviarJson([
            'success' => false,
            'message' => 'Falha ao consultar os suprimentos via SNMP. Verifique os logs da aplicação.'
        ], 502);
    } finally {
        if ($snmp instanceof SNMP) {
            $snmp->close();
        }
    }
}

try {
    $db = conectarBanco();

    $tableExistedValue = $db->query(
        "SELECT to_regclass('public.impressoras') IS NOT NULL"
    )->fetchColumn();

    $tableExisted = in_array(
        $tableExistedValue,
        [true, 1, '1', 't', 'true'],
        true
    );

    $db->exec('
        CREATE TABLE IF NOT EXISTS impressoras (
            id BIGSERIAL PRIMARY KEY,
            patrimonio VARCHAR(80) NOT NULL UNIQUE,
            modelo VARCHAR(180) NOT NULL,
            ip VARCHAR(80) NOT NULL,
            mac VARCHAR(80) NOT NULL,
            localizacao VARCHAR(180) NOT NULL,
            tipo VARCHAR(80) NOT NULL,
            disponivel BOOLEAN NOT NULL DEFAULT TRUE,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )
    ');

    // Insere os quatro exemplos somente se a tabela ainda não existia.
    if (!$tableExisted) {
        $seed = $db->prepare('
            INSERT INTO impressoras (
                patrimonio,
                modelo,
                ip,
                mac,
                localizacao,
                tipo,
                disponivel
            )
            VALUES (
                :patrimonio,
                :modelo,
                :ip,
                :mac,
                :localizacao,
                :tipo,
                :disponivel
            )
            ON CONFLICT (patrimonio) DO NOTHING
        ');

        $examples = [
            [
                '12345',
                'HP LaserJet Pro M404dn',
                '192.168.1.3',
                '11231dd34ef4',
                'Sala 101',
                'Laser',
                true
            ],
            [
                '67890',
                'Brother HL-1202',
                '192.168.1.4',
                '11231dd34ef5',
                'Sala 202',
                'Laser',
                false
            ],
            [
                '11223',
                'Epson EcoTank L3150',
                '192.168.1.5',
                '11231dd34ef6',
                'Sala 303',
                'Jato de tinta',
                true
            ],
            [
                '44556',
                'Canon G3111',
                '192.168.1.6',
                '11231dd34ef7',
                'Sala 404',
                'Jato de tinta',
                false
            ]
        ];

        foreach ($examples as [
            $patrimonio,
            $modelo,
            $ip,
            $mac,
            $localizacao,
            $tipo,
            $disponivel
        ]) {
            $seed->execute([
                'patrimonio' => $patrimonio,
                'modelo' => $modelo,
                'ip' => $ip,
                'mac' => $mac,
                'localizacao' => $localizacao,
                'tipo' => $tipo,
                'disponivel' => $disponivel
            ]);
        }
    }

    // Consulta os suprimentos de uma impressora.
    if (
        $method === 'GET'
        && ($_GET['action'] ?? '') === 'supplies'
    ) {
        $id = filter_var(
            $_GET['id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if (!$id) {
            enviarJson([
                'success' => false,
                'message' => 'Identificador de impressora inválido.'
            ], 400);
        }

        $statement = $db->prepare(
            'SELECT modelo, ip FROM impressoras WHERE id = :id'
        );

        $statement->execute(['id' => $id]);
        $printer = $statement->fetch();

        if (!$printer) {
            enviarJson([
                'success' => false,
                'message' => 'Impressora não encontrada.'
            ], 404);
        }

        enviarJson([
            'success' => true,
            'printer' => [
                'modelo' => $printer['modelo'],
                'ip' => $printer['ip']
            ],
            'checked_at' => date(DATE_ATOM),
            'supplies' => consultarSuprimentos(
                $printer['ip'],
                getenv('SNMP_COMMUNITY') ?: 'public'
            )
        ]);
    }

    // Lista todas as impressoras.
    if ($method === 'GET') {
        $rows = $db->query('
            SELECT
                id,
                patrimonio,
                modelo,
                ip,
                mac,
                localizacao,
                tipo,
                disponivel,
                created_at
            FROM impressoras
            ORDER BY modelo
        ')->fetchAll();

        enviarJson($rows);
    }

    // Cadastra uma impressora.
    if ($method === 'POST') {
        $data = validarImpressora(lerJson());

        $statement = $db->prepare('
            INSERT INTO impressoras (
                patrimonio,
                modelo,
                ip,
                mac,
                localizacao,
                tipo,
                disponivel
            )
            VALUES (
                :patrimonio,
                :modelo,
                :ip,
                :mac,
                :localizacao,
                :tipo,
                :disponivel
            )
            RETURNING id
        ');

        $statement->execute($data);

        enviarJson([
            'success' => true,
            'id' => $statement->fetchColumn(),
            'message' => 'Impressora cadastrada.'
        ], 201);
    }

    // Atualiza uma impressora.
    if ($method === 'PUT') {
        $id = filter_var(
            $_GET['id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if (!$id) {
            enviarJson([
                'success' => false,
                'message' => 'Identificador de impressora inválido.'
            ], 400);
        }

        $data = validarImpressora(lerJson());
        $data['id'] = $id;

        $statement = $db->prepare('
            UPDATE impressoras
            SET
                patrimonio = :patrimonio,
                modelo = :modelo,
                ip = :ip,
                mac = :mac,
                localizacao = :localizacao,
                tipo = :tipo,
                disponivel = :disponivel
            WHERE id = :id
        ');

        $statement->execute($data);

        if ($statement->rowCount() === 0) {
            $exists = $db->prepare(
                'SELECT 1 FROM impressoras WHERE id = :id'
            );

            $exists->execute(['id' => $id]);

            if (!$exists->fetchColumn()) {
                enviarJson([
                    'success' => false,
                    'message' => 'Impressora não encontrada.'
                ], 404);
            }
        }

        enviarJson([
            'success' => true,
            'message' => 'Impressora atualizada.'
        ]);
    }

    // Exclui uma impressora.
    if ($method === 'DELETE') {
        $id = filter_var(
            $_GET['id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if (!$id) {
            enviarJson([
                'success' => false,
                'message' => 'Identificador de impressora inválido.'
            ], 400);
        }

        $statement = $db->prepare(
            'DELETE FROM impressoras WHERE id = :id'
        );

        $statement->execute(['id' => $id]);

        if ($statement->rowCount() === 0) {
            enviarJson([
                'success' => false,
                'message' => 'Impressora não encontrada.'
            ], 404);
        }

        enviarJson([
            'success' => true,
            'message' => 'Impressora excluída.'
        ]);
    }

    enviarJson([
        'success' => false,
        'message' => 'Método não permitido.'
    ], 405);

} catch (PDOException $error) {
    if ($error->getCode() === '23505') {
        enviarJson([
            'success' => false,
            'message' => 'Já existe uma impressora com esse patrimônio.'
        ], 409);
    }

    erroBanco($error);
}
