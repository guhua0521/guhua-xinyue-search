<?php

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use app\service\SiteService;

/**
 * 一键开通分站命令
 *
 * 用法示例：
 *   php think site:create --name=XX资源站 --domain=ziyuan.example.com
 *   php think site:create --name=XX资源站 --domain=ziyuan.example.com --account=site01 --password=abc123456
 *   php think site:create --name=XX资源站 --domain=ziyuan.example.com --expire=2026-12-31
 */
class CreateSite extends Command
{
    protected function configure()
    {
        $this->setName('site:create')
            ->addOption('name', null, Option::VALUE_REQUIRED, '分站名称')
            ->addOption('domain', null, Option::VALUE_REQUIRED, '绑定域名')
            ->addOption('key', null, Option::VALUE_OPTIONAL, '分站标识（留空自动生成）')
            ->addOption('expire', null, Option::VALUE_OPTIONAL, '到期时间（YYYY-MM-DD，留空永久）')
            ->addOption('account', null, Option::VALUE_OPTIONAL, '管理员账号（留空自动生成）')
            ->addOption('password', null, Option::VALUE_OPTIONAL, '管理员密码（留空自动生成）')
            ->setDescription('一键开通网盘搜索分站');
    }

    protected function execute(Input $input, Output $output)
    {
        $data = [
            'site_name' => $input->getOption('name'),
            'site_domain' => $input->getOption('domain'),
            'site_key' => $input->getOption('key') ?? '',
            'site_expire' => 0,
            'admin_account' => $input->getOption('account') ?? '',
            'admin_password' => $input->getOption('password') ?? '',
        ];

        if (!empty($input->getOption('expire'))) {
            $ts = strtotime($input->getOption('expire'));
            if (!$ts) {
                $output->writeln('<error>到期时间格式错误，示例：--expire=2026-12-31</error>');
                return 1;
            }
            $data['site_expire'] = $ts;
        }

        $result = SiteService::createSite($data, $errMsg);
        if (!$result) {
            $output->writeln('<error>' . ($errMsg ?? '开通失败') . '</error>');
            return 1;
        }

        $output->writeln('');
        $output->writeln('<info>========== 分站开通成功 ==========</info>');
        $output->writeln('分站名称：' . $result['site_name']);
        $output->writeln('前台地址：http://' . $result['site_domain']);
        $output->writeln('后台地址：http://' . $result['site_domain'] . '/qfadmin/admin/login');
        $output->writeln('管理员账号：' . $result['admin_account']);
        $output->writeln('管理员密码：' . $result['admin_password']);
        $output->writeln('分站标识：' . $result['site_key']);
        $output->writeln('<info>==================================</info>');
        return 0;
    }
}
