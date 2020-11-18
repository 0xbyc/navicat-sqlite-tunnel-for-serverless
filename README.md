# Navicat SQLite Http Tunnel For Serverless

navicat SQLite Http通道适配Serverless

目前适配了腾讯云的Serverless,可以用navicate管理挂载到[云函数](https://cloud.tencent.com/product/scf)中的SQLite数据库



## 背景

可以在云函数中挂载文件系统[CFS](https://cloud.tencent.com/product/cfs)当做数据盘用,像SQLite这种无依赖的数据库就很适合用在其中,做一个小型的数据库就很美滋滋,但无法使用Navicat这种数据库管理工具去连接管理,所幸的是Navicat里面提供了http通道,但是在serverless是没法使用的。

## 适配思路

项目根目录下`ntunnel_sqlite.php`为navicat自带的http通道文件，基于这个文件修改让其可以在serverless环境下运行

## 部署

### 手动部署

#### 1.新建一个函数

选择环境Php7.2，空白函数

![](https://cdn.jsdelivr.net/gh/cooldev-cn/cdn@latest/20201117183712.png)

创建一个云函数,注意和你的文件系统CFS要在一个区，比如广州，将项目`src`目录下的文件复制到

修改执行方法为`tunnel.main_handler`

![](https://cdn.jsdelivr.net/gh/cooldev-cn/cdn@latest/20201117183923.png)

往下拉有一个高级设置，展开：

>  超时时间设置为`900`秒,防止在使用导出一类sql语句时超时

![](https://cdn.jsdelivr.net/gh/cooldev-cn/cdn@latest/20201117184211.png)

启用权限配置并选择`SCF_QcsRole`

![](https://cdn.jsdelivr.net/gh/cooldev-cn/cdn@latest/20201117184342.png)

勾选私有网络（启用文件系统必须勾选私有网络），公有网络用不上，可以取消掉

选择私有网络的交换机（选择不对文件系统对应ID找不到）

如果没有文件系统可以点击`新建文件系统`去创建

> 注意这个地方：云函数想要读取文件系统的数据是需要权限的，可以参考[官方文档](https://cloud.tencent.com/document/product/583/46199)去操作，一定要授权，不然没有权限

本地目录和远程目录不用改，默认为`/mnt/`,在云函数中读取的时候比如`/mnt/a.txt`实际找的就是文件系统的`/a.txt`文件

![](https://cdn.jsdelivr.net/gh/cooldev-cn/cdn@latest/20201117184624.png)

返回到文件编辑区，系统默认创建一个`index.php`,将其删除

创建两个文件`tunnel.php`和`test.html`，将项目`src`目录下对应文件内容复制粘贴进去

![](https://cdn.jsdelivr.net/gh/cooldev-cn/cdn@latest/20201117185840.png)

点击`完成`进行保存

#### 2.创建API网关触发器

触发管理-创建触发器，选择`API网关触发器`，取一个名字，选择`新建API服务`，勾选`启用集成响应`，提交

![](https://cdn.jsdelivr.net/gh/cooldev-cn/cdn@latest/20201117190026.png)

创建成功后可以看到一个`访问路径`，点击可以看到一个测试页面，但此时还没有完全配置好

> 访问地址复制下来，到Navicat里面连接时需要用到

![](https://cdn.jsdelivr.net/gh/cooldev-cn/cdn@latest/20201117190528.png)

测试页面

![](https://cdn.jsdelivr.net/gh/cooldev-cn/cdn@latest/20201117190445.png)

点击`API服务名`后面的链接跳转到API网关设置页面，可以看到系统创建好的API路径，点击编辑

![](https://cdn.jsdelivr.net/gh/cooldev-cn/cdn@latest/20201117190640.png)

参数配置-新增参数配置，参数位置全部为`Body`，类型全部为`string`，没有默认值，都是非必填

| 参数名       | 参数位置 | 类型   | 备注           |
| ------------ | -------- | ------ | -------------- |
| actn         | Body     | string | 动作           |
| dbfile       | Body     | string | 数据库文件     |
| encodeBase64 | Body     | string | 是否base64编码 |
| q            | Body     | string | 查询语句       |
| version      | Body     | string | 版本           |

![](https://cdn.jsdelivr.net/gh/cooldev-cn/cdn@latest/20201117191115.png)

下一步，后端超时可以改为`1800`秒，防止超时，再下一步就不用设置了，点击完成，选择`前往发布服务`

> 一定要发布，不然修改是不生效的

![](https://cdn.jsdelivr.net/gh/cooldev-cn/cdn@latest/20201117191248.png)

调转到页面点击右上角`发布`

![](https://cdn.jsdelivr.net/gh/cooldev-cn/cdn@latest/20201118094936.png)

发布环境选择`发布`，备注随便填写，提交

## 自动部署

使用 [Serverless Framework CLI](https://cloud.tencent.com/document/product/583/44750) 进行部署

1.安装nodejs，推荐版本12.16

2.[配置权限](https://cloud.tencent.com/document/product/583/44786)

3.克隆本仓库

4.手动创建好文件系统CFS

5.修改`.env.example`为`.env`并修改其中的配置

6.在项目目录下使用`serverless deploy`部署，API网关都会自动配置好

## 测试

打开Navicat，新建连接，打开`HTTP`选项卡，勾选`使用HTTP通道`，在API网关页面复制公网访问地址，粘贴进去，再切回到`常规`选项卡，取个名字，可以选择`新建SQLite3`,数据库文件填写`/mnt/tunnel.db`,点击`确定`，如果不报错就是可以连接的

![](https://cdn.jsdelivr.net/gh/cooldev-cn/cdn@latest/20201117192823.png)

![](https://cdn.jsdelivr.net/gh/cooldev-cn/cdn@latest/20201117192259.png)

