<?php
return [
  "smtp" => [
    "host" => "smtp.office365.com",
    "port" => 587,
    "secure" => "tls",
    "username" => "contato@cliente.com",
    "password" => "SENHA_AQUI",
  ],
  "from" => [
    "email" => "contato@cliente.com",
    "name"  => "Empresa do Cliente",
  ],
  "defaults" => [
    "charset" => "UTF-8",
    "debug"   => false,
  ],
];
