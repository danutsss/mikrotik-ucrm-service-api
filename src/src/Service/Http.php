<?php

declare(strict_types = 1);

namespace MikrotikService\Service;

class Http {
    public static function forbidden(): void {
        if(!headers_sent()) {
            header("HTTP/1.1 403 Forbidden");
        }

        die('<b>Doar clientii platformei au permisiunea sa acceseze aceasta pagina!</b>');
    }
}