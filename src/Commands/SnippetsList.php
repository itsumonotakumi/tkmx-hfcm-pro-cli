<?php

declare(strict_types=1);

namespace Tkmx\HfcmCli\Commands;

use Tkmx\HfcmCli\Console\Args;
use Tkmx\HfcmCli\Console\ExitCode;

class SnippetsList extends AbstractCommand
{
    protected string $requiredCap = 'read';

    protected function commandName(): string
    {
        return 'snippets:list';
    }

    protected function execute(Args $args): int
    {
        $params = [];
        if ($args->has('page'))     $params['page']     = $args->getInt('page', 1);
        if ($args->has('per_page')) $params['per_page'] = $args->getInt('per_page', 20);
        if ($args->has('orderby'))  $params['orderby']  = $args->getString('orderby');
        if ($args->has('order'))    $params['order']    = $args->getString('order');
        if ($args->has('status'))   $params['status']   = $args->getString('status');
        if ($args->has('search'))   $params['search']   = $args->getString('search');

        $result = \HFCM_Takumi_API_Snippet_Service::get_snippets($params);

        if (is_wp_error($result)) {
            return $this->handleWpError($result);
        }

        $format = $args->getString('format', 'json');
        $snippets = $result['snippets'] ?? [];

        $this->output->success($snippets, [
            'total'    => $result['total'] ?? count($snippets),
            'page'     => $result['page'] ?? 1,
            'per_page' => $result['per_page'] ?? count($snippets),
        ], $format);

        return ExitCode::OK;
    }
}
