<?php
$esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8");
$nl2br_esc = fn($s) => nl2br($esc($s));

return [
  "team_calc_submitted" => function(array $p) use ($esc, $nl2br_esc) {
    return [
      "subject" => "Novo cálculo + formulário (site)",
      "html" => "
        <h2>Novo cálculo + formulário</h2>
        <p><b>Nome:</b> {$esc($p['name'] ?? '')}</p>
        <p><b>Email:</b> {$esc($p['email'] ?? '')}</p>
        <p><b>Telefone:</b> {$esc($p['phone'] ?? '')}</p>
        <hr>
        <p><b>Setor:</b> {$esc($p['sector'] ?? '')}</p>
        <p><b>Opção:</b> {$esc($p['option'] ?? '')}</p>
        <p><b>Resultado:</b> {$esc($p['calc_result'] ?? '')}</p>
        <hr>
        <p><b>Mensagem:</b><br>{$nl2br_esc($p['message'] ?? '')}</p>
        <hr>
        <p><small><b>Data:</b> {$esc($p['timestamp'] ?? '')}</small></p>
      ",
    ];
  },

  "lead_confirmation" => function(array $p) use ($esc) {
    $name = $esc($p['name'] ?? '');
    return [
      "subject" => "Recebemos seu pedido — próximos passos",
      "html" => "
        <h2>Recebemos sua solicitação</h2>
        <p>Obrigado{$name ? ", {$name}" : ""}. Registramos seu pedido.</p>
        <ul>
          <li><b>Setor:</b> {$esc($p['sector'] ?? '')}</li>
          <li><b>Opção:</b> {$esc($p['option'] ?? '')}</li>
          <li><b>Resultado:</b> {$esc($p['calc_result'] ?? '')}</li>
        </ul>
        <p>Em breve entraremos em contato.</p>
      ",
    ];
  },
];
