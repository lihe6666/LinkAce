# 部署说明（bookmarks.yanso8.cn）

## 线上环境

- 服务器：`1.94.196.170:2222`（root）
- 入口链路：Safeline → 宝塔 nginx（:8001）→ PHP-FPM 8.4
- 站点目录：`/www/wwwroot/bookmarks.yanso8.cn`
- 数据库：MySQL，库名 `linkace`（见站点 `.env`）
- 备份目录：`/www/backup/`

## 部署方式（git）

线上目录已经是一个 git 仓库，跟踪 `origin/2.x`
（`https://github.com/lihe6666/LinkAce.git`）。

日常更新执行：

```bash
ssh -p 2222 root@1.94.196.170 'bash /www/wwwroot/deploy-bookmarks.sh'
```

脚本做的事：

1. `git pull --ff-only origin 2.x`
2. `composer install --no-dev --optimize-autoloader`
3. `php artisan migrate --force`
4. `php artisan optimize:clear`
5. 输出当前部署的提交

只改 PHP / Blade 时，这个脚本就够了。

## 注意事项

1. **服务器没装 Node**：改动了 `resources/assets/` 时，需要本地执行 `npm run build`，
   然后把构建产物同步上去，最后再跑部署脚本：

   ```bash
   npm run build
   rsync -azR -e "ssh -p 2222" public/build root@1.94.196.170:/www/wwwroot/bookmarks.yanso8.cn/
   ```

2. **服务器本地文件不在 git 里**：`.env`、`storage/`、`.htaccess`、`.user.ini`
   都是线上本地文件，`git pull` 不会动它们。**不要执行 `git clean -fd`**，
   会把这些文件删掉。

3. **AI 功能依赖 `.env`**：`LLM_API_KEY`（必需）、`LLM_BASE_URL`、`LLM_MODEL`、
   `AI_TAG_MODE`。AI 打标签按钮、`links:ai-tag`、`tags:merge-similar` 都用这组配置。

4. **版本号**：页面显示的 "Current version" 来自 `package.json` 的 `version`
   （由 `UpdateHelper` 读取并缓存一天）。升级后执行 `php artisan optimize:clear`
   清掉缓存再刷新。

5. **与上游同步（需要代理）**：

   ```bash
   https_proxy=http://127.0.0.1:7897 http_proxy=http://127.0.0.1:7897 \
     git fetch upstream && git merge upstream/2.x
   ```

   本地目前领先上游、无落后；上游是 `Kovah/LinkAce`。

6. **升级检查**：`composer.lock` 有变化时必须跑 `composer install`；
   有数据库迁移时必须跑 `php artisan migrate --force`。

7. **升级前先备份**：

   ```bash
   tar czf /www/backup/linkace-$(date +%Y%m%d-%H%M%S).tar.gz \
     --exclude=node_modules --exclude=vendor \
     -C /www/wwwroot bookmarks.yanso8.cn
   mysqldump ... linkace > /www/backup/linkace-db-$(date +%Y%m%d-%H%M%S).sql
   ```

## 变更记录

### 2026-11-15

- 线上从 v2.6.1 发布包（手工拷贝文件）迁移为 git 检出，版本对齐 **v2.6.3**（提交 `1975ca3f`）。
- 备份：`/www/backup/linkace-pre-git-20261115-142301.tar.gz`、
  `/www/backup/linkace-db-20261115-142313.sql`。
- 线上已有的自定义功能：AI 打标签命令与表单按钮、AI 相似标签合并（`tags:merge-similar`
  命令 + `/tags/merge` 页面）、链接重复检测清理页、搜索页删除按钮。
