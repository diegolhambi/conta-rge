<?php
include __DIR__."/../vendor/autoload.php";

use Symfony\Component\Dotenv\Dotenv;
use PHPMailer\PHPMailer\PHPMailer;
use GuzzleHttp\Client;

if (file_exists(__DIR__."/../.env")) {
    $dotenv = new Dotenv();
    $dotenv->load(__DIR__."/../.env");
}

if (!array_key_exists("RGE_USERNAME", $_SERVER) or
    !array_key_exists("RGE_PASSWORD", $_SERVER)) {
    throw new \Exception("RGE VARIABLES NOT DEFINED", 1);
}

$client = new Client([
    "base_uri" => "https://servicosonline.cpfl.com.br/agencia-webapi/api/",
    "headers"  => [
        "User-Agent"      => "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:68.0) Gecko/20100101 Firefox/68.0",
        "Accept"          => "application/json, text/plain, */*",
        "Accept-Language" => "pt-BR,pt;q=0.8,en-US;q=0.5,en;q=0.3",
    ]
]);

$response = $client->post("token", [
    "form_params" => [
        "client_id"  => "agencia-virtual-cpfl-web",
        "grant_type" => "password",
        "username"   => $_SERVER["RGE_USERNAME"],
        "password"   => $_SERVER["RGE_PASSWORD"],
    ]
]);

$sessionInfo = json_decode($response->getBody(), true);
$sessionInfo["Instalacao"] = json_decode($sessionInfo["Instalacao"], true);
$sessionInfo["ListaInstalacoes"] = json_decode($sessionInfo["ListaInstalacoes"], true);

$response = $client->post("historico-contas/validar-situacao", [
    "headers" => [
        "Authorization" => "Bearer {$sessionInfo["access_token"]}"
    ],
    "json" => [
        "RetornarDetalhes" => true,
        "CodigoFase"       => $sessionInfo["Instalacao"]["CodigoFase"],
        "IndGrupoA"        => $sessionInfo["Instalacao"]["IndGrupoA"],
        "Situacao"         => $sessionInfo["Instalacao"]["Situacao"],
        "ContaContrato"    => $sessionInfo["Instalacao"]["ContaContrato"],
        "CodigoClasse"     => $sessionInfo["Instalacao"]["CodClasse"],
        "CodEmpresaSAP"    => $sessionInfo["Instalacao"]["CodEmpresaSAP"],
        "Instalacao"       => $sessionInfo["Instalacao"]["Instalacao"],
        "ParceiroNegocio"  => $sessionInfo["Instalacao"]["ParceiroNegocio"],
        "GerarProtocolo"   => true,
    ]
]);

$openBills = json_decode($response->getBody(), true);

$pdfBillQuery = [];
$billReferenceMonth = [];
foreach ($openBills["ContasAberto"] as $bill) {
    $billReferenceMonth[] = $bill["MesReferencia"];

    $pdfBillQuery[] = [
        "numeroContaEnergia" => $bill["NumeroContaEnergia"],
        "codigoClasse"       => $sessionInfo["Instalacao"]["CodClasse"],
        "codEmpresaSAP"      => $sessionInfo["Instalacao"]["CodEmpresaSAP"],
        "instalacao"         => $sessionInfo["Instalacao"]["Instalacao"],
        "parceiroNegocio"    => $sessionInfo["Instalacao"]["ParceiroNegocio"],
        "token"              => $sessionInfo["access_token"],
        "contaAcumulada"     => ($bill["ContaAcumulada"]) ? "true" : "false",
    ];
}

foreach ($pdfBillQuery as $index => $query) {
    $fatura = tmpfile();
    $response = $client->get("historico-contas/conta-completa", [
        "query" => $query,
        "sink"  => $fatura
    ]);

    $faturaUri = stream_get_meta_data($fatura)["uri"];
    try {
        $message = new PHPMailer(true);

        //$message->SMTPDebug = 3;
        $message->CharSet    = "UTF-8";
        $message->isSMTP();
        $message->Host       = $_SERVER["SMTP_HOST"];
        $message->Port       = $_SERVER["SMTP_PORT"];
        $message->SMTPSecure = "tls";
        $message->SMTPAuth   = true;
        $message->Username   = $_SERVER["SMTP_USER"];
        $message->Password   = $_SERVER["SMTP_PASSWORD"];

        $message->setFrom($_SERVER["SMTP_FROM"]);
        $message->addAddress($_SERVER["RECIPIENT"]);

        $message->Subject = "RGE Mês Ref. {$billReferenceMonth[$index]} - Conta de Luz";
        $message->Body = "Hello, world!";
        $message->addAttachment($faturaUri, "fatura-{$query["numeroContaEnergia"]}.pdf");

        echo ($message->send()) ? "true" : "false";
    } catch (\Exception $e) {
        echo $e->message;
    }

    fclose($fatura);

    echo "\n";
}
