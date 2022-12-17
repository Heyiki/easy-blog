# Easy Blog Api

## 配置 integrations [https://developers.notion.com/docs]

注册完 notion 之后，进入https://www.notion.so/my-integrations 新建 integrations

填写好 Name 之后 Associated workspace 选择自己的账户，然后点击 Submit

然后记录 (顶部) `Secrets` - `Internal Integration Token` [notion 令牌（注意不要泄露）]

## 配置 Page

https://www.notion.so/eb0275c5f98d4288ade90654f6942840?v=f01221add08549348744cba580ad1ce3

## 配置 vercel

fork 项目`https://github.com/Heyiki/`到自己的 github 上，然后登录 vercel，新建项目-选择 continue with github-然后 import 对应的项目
然后 Environment Variables 填上前面记录的 `notion 令牌`、`database_id`

格式：

name:`NOTION_TOKEN` value:notion 令牌

name:`DATABASE_ID` value:database_id 值

配置好之后，查看 build log 没问题就可以访问了[注：由于国内 vercel 的 dns 被污染了，目前只能在项目的 setting-domains 上添加自己的域名访问]