<?php

// +----------------------------------------------------------------------
// | ThinkAdmin
// +----------------------------------------------------------------------
// | 版权所有 2014~2021 广州楚才信息科技有限公司 [ http://www.cuci.cc ]
// +----------------------------------------------------------------------
// | 官方网站: https://thinkadmin.top
// +----------------------------------------------------------------------
// | 开源协议 ( https://mit-license.org )
// | 免费声明 ( https://thinkadmin.top/disclaimer )
// +----------------------------------------------------------------------
// | gitee 代码仓库：https://gitee.com/zoujingli/ThinkAdmin
// | github 代码仓库：https://github.com/zoujingli/ThinkAdmin
// +----------------------------------------------------------------------

namespace app\wechat\command;

use app\wechat\model\WechatFans;
use app\wechat\model\WechatFansTags;
use app\wechat\service\FansService;
use app\wechat\service\WechatService;
use think\admin\Command;

/**
 * 微信粉丝管理指令
 * Class Fans
 * @package app\wechat\command
 */
class Fans extends Command
{
    /**
     * 配置指令
     */
    protected function configure()
    {
        $this->setName('xadmin:fansall');
        $this->setDescription('Wechat Users Data Synchronize for ThinkAdmin');
    }

    /**
     * 任务执行处理
     * @throws \WeChat\Exceptions\InvalidResponseException
     * @throws \WeChat\Exceptions\LocalCacheException
     * @throws \think\admin\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function handle()
    {
        $message = $this->_list();
        $message .= $this->_tags();
        $message .= $this->_black();
        $this->setQueueSuccess($message);
    }

    /**
     * 同步微信粉丝列表
     * @param string $next
     * @param integer $done
     * @return string
     * @throws \WeChat\Exceptions\InvalidResponseException
     * @throws \WeChat\Exceptions\LocalCacheException
     * @throws \think\admin\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    protected function _list(string $next = '', int $done = 0): string
    {
        $appid = WechatService::instance()->getAppid();
        $this->output->comment('开始获取微信用户数据');
        while (is_string($next)) {
            $result = WechatService::WeChatUser()->getUserList($next);
            if (is_array($result) && !empty($result['data']['openid'])) {
                foreach (array_chunk($result['data']['openid'], 100) as $openids) {
                    $info = WechatService::WeChatUser()->getBatchUserInfo($openids);
                    if (is_array($info) && !empty($info['user_info_list'])) {
                        foreach ($info['user_info_list'] as $user) if (isset($user['nickname'])) {
                            $this->queue->message($result['total'], ++$done, "-> {$user['openid']} {$user['nickname']}");
                            FansService::instance()->set($user, $appid);
                        }
                    }
                }
                $next = $result['total'] > $done ? $result['next_openid'] : null;
            } else {
                $next = null;
            }
        }
        $this->output->comment($done > 0 ? '微信用户数据获取完成' : '未获取到微信用户数据');
        $this->output->newLine();
        return "共获取 {$done} 个用户数据";
    }

    /**
     * 同步粉丝黑名单列表
     * @param string $next
     * @param integer $done
     * @return string
     * @throws \WeChat\Exceptions\InvalidResponseException
     * @throws \WeChat\Exceptions\LocalCacheException
     */
    public function _black(string $next = '', int $done = 0): string
    {
        $wechat = WechatService::WeChatUser();
        $this->setQueueProgress("开始更新黑名单的微信用户");
        
        //解决：如果本地拉黑，在官方删除后，获取粉丝，本地黑名单依旧在
        $this->setQueueProgress("开始删除本地黑名单用户");
        WechatFans::mk()->update(['is_black' => 1])->delete();
        
        while (!is_null($next) && is_array($result = $wechat->getBlackList($next)) && !empty($result['data']['openid'])) {
            foreach (array_chunk($result['data']['openid'], 100) as $chunk) {
                $done += count($chunk);
                $map = [['is_black', '=', 0], ['openid', 'in', $chunk]];
                WechatFans::mk()->where($map)->update(['is_black' => 1]);
            }
            $next = $result['total'] > $done ? $result['next_openid'] : null;
        }
        $this->setQueueProgress('完成更新黑名单的微信用户', null, -1);
        $this->output->newLine();
        if (empty($result['total'])) {
            return ', 其中黑名单 0 人';
        } else {
            return ", 其中黑名单 {$result['total']} 人";
        }
    }

    /**
     * 同步粉丝标签列表
     * @param integer $done
     * @return string
     * @throws \WeChat\Exceptions\InvalidResponseException
     * @throws \WeChat\Exceptions\LocalCacheException
     * @throws \think\admin\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function _tags(int $done = 0): string
    {
        $appid = WechatService::instance()->getAppid();
        $this->output->comment('开始获取微信用户标签数据');
        if (is_array($list = WechatService::WeChatTags()->getTags()) && !empty($list['tags'])) {
            $count = count($list['tags']);
            foreach ($list['tags'] as &$tag) {
                $tag['appid'] = $appid;
                $this->queue->message($count, ++$done, "-> {$tag['name']}");
            }
            WechatFansTags::mk()->where(['appid' => $appid])->delete();
            WechatFansTags::mk()->insertAll($list['tags']);
        }
        $this->output->comment($done > 0 ? '微信用户标签数据获取完成' : '未获取到微信用户标签数据');
        $this->output->newLine();
        return ", 获取到 {$done} 个标签";
    }
}
