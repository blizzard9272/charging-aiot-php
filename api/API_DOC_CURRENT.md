# Charging AIOT API 文档

本文档基于当前后端代码结构整理，覆盖当前版本实际可用的 API 入口。

推荐优先使用“主入口路径”。文档中同时标注了仍然保留的“兼容入口路径”，这些路径主要用于兼容旧前端或旧脚本，不建议继续作为新增对接的正式地址。

## 1. 通用约定

### 1.1 基础返回结构

成功：

```json
{
  "code": 1,
  "msg": "success",
  "data": {}
}
```

失败：

```json
{
  "code": 0,
  "msg": "error message",
  "data": null
}
```

### 1.2 鉴权

- 需要登录鉴权的接口必须携带请求头：`Authorization: Bearer <token>`
- 当前不需要 JWT 的接口主要是“协议数据上传类接口”和“协议记录查询类接口”

### 1.3 登录密码格式

登录接口和人员管理接口中的密码字段，后端支持两种输入形式：

- 明文密码
- 64 位小写/大写 SHA-256 摘要字符串

前端当前采用 SHA-256 摘要方式提交。

## 2. 认证模块

### 2.1 登录

- 主入口：`POST /charging-aiot-php/api/auth.php?action=login`
- 功能：用户登录，签发 JWT
- 是否鉴权：否

请求体：

```json
{
  "username": "admin",
  "password": "123456"
}
```

请求参数说明：

- `username`: 登录账号，必填
- `password`: 登录密码，必填，支持明文或 SHA-256 摘要

返回 `data` 结构：

- `token`: JWT 字符串
- `tokenType`: 固定为 `Bearer`
- `expiresIn`: 过期秒数，当前为 `7200`
- `expiresAt`: 过期时间戳（秒）
- `user.user_id`: 用户 ID
- `user.user__uuid`: 用户 UUID
- `user.username`: 用户名
- `user.role`: 角色编号

返回示例：

```json
{
  "code": 1,
  "msg": "Login success",
  "data": {
    "token": "xxx",
    "tokenType": "Bearer",
    "expiresIn": 7200,
    "expiresAt": 1777362395,
    "user": {
      "user_id": 1,
      "user__uuid": "13de7bbe-b26a-4171-b23f-03e26c849c13",
      "username": "admin",
      "role": 1
    }
  }
}
```

### 2.2 当前登录用户

- 主入口：`GET /charging-aiot-php/api/auth.php?action=me`
- 功能：获取当前 JWT 对应的用户信息
- 是否鉴权：是

返回 `data` 结构：

- `user_id`
- `user__uuid`
- `username`
- `role`

## 3. 监控中心 Monitor Center

说明：

- 统一业务服务入口：`/charging-aiot-php/api/monitor-center/common/monitor_service.php`
- 对外主入口按前端导航拆分为：
  - `device-center`
  - `group-management`
  - `workbench`
  - `video-list`
- 兼容入口：`/charging-aiot-php/api/monitor-center/monitor.php?action=...`

### 3.1 设备列表

- 主入口：`GET /charging-aiot-php/api/monitor-center/device-center/list.php`
- 兼容入口：`GET /charging-aiot-php/api/monitor-center/monitor.php?action=pageDevices`
- 功能：分页查询监控设备及其默认流配置
- 是否鉴权：是

Query 参数：

- `page`: 页码，默认 `1`
- `pageSize`: 每页条数，默认 `20`，最大 `200`
- `deviceName`: 设备名称模糊查询
- `brand`: 品牌模糊查询
- `model`: 型号模糊查询
- `ipAddress`: IP 模糊查询
- `groupId`: 分组 ID
- `protocolType`: 协议类型
- `onlineStatus`: 在线状态
- `statusFlag`: 状态标记；不传时默认过滤掉逻辑删除状态 `4`

返回 `data` 结构：

- `total`: 总数
- `records`: 设备数组

`records[*]` 主要字段：

- `deviceId`
- `deviceUuid`
- `deviceName`
- `brand`
- `model`
- `ipAddress`
- `port`
- `location`
- `protocolType`
- `onlineStatus`
- `statusFlag`
- `groupName`
- `pathId`
- `pathUuid`
- `pathName`
- `sourceUrl`
- `streamType`
- `recordEnabled`
- `recordPath`
- `recordFormat`
- `recordPartDuration`
- `pathStatusFlag`
- `defaultStreamUrl`
- `defaultStreamProtocol`
- `isRecording`

### 3.2 设备表单详情

- 主入口：`GET /charging-aiot-php/api/monitor-center/device-center/form.php?id={deviceId}`
- 兼容入口：`GET /charging-aiot-php/api/monitor-center/monitor.php?action=deviceForm&id={deviceId}`
- 功能：获取单个设备及其最新流配置，用于查看/编辑回显
- 是否鉴权：是

Query 参数：

- `id`: 设备 ID，必填

返回 `data` 主要字段：

- `groupId`
- `groupName`
- `deviceId`
- `deviceName`
- `deviceUuid`
- `brand`
- `model`
- `protocolType`
- `ipAddress`
- `port`
- `username`
- `password`
- `location`
- `onlineStatus`
- `statusFlag`
- `pathId`
- `pathUuid`
- `pathName`
- `sourceUrl`
- `streamType`
- `recordEnabled`
- `recordPath`
- `recordFormat`
- `recordPartDuration`
- `pathStatusFlag`

### 3.3 设备流列表

- 主入口：`GET /charging-aiot-php/api/monitor-center/device-center/streams.php?id={deviceId}`
- 兼容入口：`GET /charging-aiot-php/api/monitor-center/monitor.php?action=deviceStreams&id={deviceId}`
- 功能：获取设备下所有流记录
- 是否鉴权：是

Query 参数：

- `id`: 设备 ID，必填

返回 `data` 结构：

- 数组，每项字段：
  - `streamId`
  - `streamUuid`
  - `deviceId`
  - `streamType`
  - `transportProtocol`
  - `streamUrl`
  - `defaultFlag`
  - `statusFlag`
  - `createTime`
  - `modifyTime`

### 3.4 新增设备

- 主入口：`POST /charging-aiot-php/api/monitor-center/device-center/create.php`
- 兼容入口：`POST /charging-aiot-php/api/monitor-center/monitor.php?action=createDevice`
- 兼容入口：`POST /charging-aiot-php/api/monitor-center/monitor.php?action=device`
- 功能：新增监控设备并创建默认流路径，同时尝试同步 MediaMTX 路径配置
- 是否鉴权：是

请求体主要字段：

- `groupId`: 分组 ID，必填
- `deviceUuid`: 设备 UUID，可选；不传时后端自动生成
- `deviceName`: 设备名称，必填
- `brand`: 品牌，必填
- `model`: 型号，必填
- `protocolType`: 协议类型，必填，当前允许 `1~3`
- `ipAddress`: 设备 IP，必填
- `port`: 端口，必填
- `username`: 设备账号，必填
- `password`: 设备密码，必填
- `confirmPassword`: 确认密码，必填，必须与 `password` 一致
- `location`: 设备位置，必填
- `onlineStatus`: 在线状态，必填，仅允许 `0/1`
- `streamType`: 流类型，必填，仅允许 `1/2`
- `pathName`: 流路径名，必填
- `recordEnabled`: 是否录像，可选，默认 `0`
- `recordPath`: 录像路径模板，可选
- `recordFormat`: 录像格式，可选，默认 `fmp4`
- `recordPartDuration`: 分片时长，可选，默认 `60`
- `pathStatusFlag`: 流路径状态，可选，默认 `1`

返回 `data` 结构：

- `deviceId`
- `pathUuid`
- `pathName`
- `sourceUrl`

### 3.5 更新设备

- 主入口：`PUT /charging-aiot-php/api/monitor-center/device-center/update.php`
- 兼容入口：`PUT /charging-aiot-php/api/monitor-center/monitor.php?action=updateDevice`
- 兼容入口：`PUT /charging-aiot-php/api/monitor-center/monitor.php?action=device`
- 功能：更新设备信息；如涉及流配置变化，会同步更新 `sys_camera_path` 和 MediaMTX 配置
- 是否鉴权：是

请求体主要字段：

- `deviceId`: 设备 ID，必填
- `groupId`
- `deviceName`
- `deviceUuid`
- `brand`
- `model`
- `protocolType`
- `ipAddress`
- `port`
- `username`
- `password`
- `location`
- `onlineStatus`
- `statusFlag`
- `pathName`
- `sourceUrl`
- `streamType`
- `recordEnabled`
- `recordPath`
- `recordFormat`
- `recordPartDuration`
- `pathStatusFlag`

返回 `data` 结构：

- 成功时通常为 `null`
- 如果发生 MediaMTX 同步信息或警告，则返回：
  - `mediaMtx`
  - `mediaMtxWarnings`

### 3.6 删除设备前检查

- 主入口：`GET /charging-aiot-php/api/monitor-center/device-center/delete-check.php?id={deviceId}`
- 兼容入口：`GET /charging-aiot-php/api/monitor-center/monitor.php?action=deleteDeviceCheck&id={deviceId}`
- 功能：删除设备前，检查对应 MediaMTX 路径是否存在
- 是否鉴权：是

Query 参数：

- `id`: 设备 ID，必填

返回 `data` 结构：

- `deviceId`
- `deviceName`
- `groupId`
- `pathName`
- `mediaMtxPathExists`
- `mediaMtxCheckSuccess`
- `mediaMtxCheckCode`
- `mediaMtxCheckMessage`

### 3.7 删除设备

- 主入口：`DELETE /charging-aiot-php/api/monitor-center/device-center/delete.php`
- 兼容入口：`DELETE /charging-aiot-php/api/monitor-center/monitor.php?action=device`
- 功能：逻辑删除设备，停用流路径，并尝试清理 MediaMTX 路径配置
- 是否鉴权：是

请求体：

```json
{
  "deviceId": 1
}
```

返回 `data` 结构：

- `deviceId`
- `removedPathNames`: 已删除的 MediaMTX 路径名数组
- `mediaMtxWarnings`: 清理失败或跳过的告警数组

### 3.8 分组列表

- 主入口：`GET /charging-aiot-php/api/monitor-center/group-management/list.php`
- 兼容入口：`GET /charging-aiot-php/api/monitor-center/monitor.php?action=groups`
- 功能：获取监控分组列表
- 是否鉴权：是

返回 `data` 结构：

- 数组，每项字段：
  - `groupId`
  - `groupUuid`
  - `groupName`
  - `sort`
  - `statusFlag`
  - `createTime`
  - `modifyTime`

### 3.9 分组及设备列表

- 主入口：`GET /charging-aiot-php/api/monitor-center/group-management/device-list.php`
- 兼容入口：`GET /charging-aiot-php/api/monitor-center/monitor.php?action=groupDeviceList`
- 功能：获取分组及其下设备列表
- 是否鉴权：是

Query 参数：

- `groupName`: 分组名称模糊查询
- `statusFlag`: 分组状态

返回 `data` 结构：

- 数组，每项字段：
  - `groupId`
  - `groupUuid`
  - `groupName`
  - `sort`
  - `statusFlag`
  - `createTime`
  - `modifyTime`
  - `deviceCount`
  - `devices`

`devices[*]` 主要字段：

- `deviceId`
- `deviceName`
- `brand`
- `model`
- `protocolType`
- `ipAddress`
- `port`
- `location`
- `onlineStatus`
- `statusFlag`

### 3.10 新增分组

- 主入口：`POST /charging-aiot-php/api/monitor-center/group-management/create.php`
- 兼容入口：`POST /charging-aiot-php/api/monitor-center/monitor.php?action=createGroup`
- 兼容入口：`POST /charging-aiot-php/api/monitor-center/monitor.php?action=group`
- 功能：新增监控分组
- 是否鉴权：是

请求体：

```json
{
  "groupName": "分组A",
  "sort": 1,
  "statusFlag": 1
}
```

返回 `data` 结构：

- `groupId`

### 3.11 更新分组

- 主入口：`PUT /charging-aiot-php/api/monitor-center/group-management/update.php`
- 兼容入口：`PUT /charging-aiot-php/api/monitor-center/monitor.php?action=updateGroup`
- 兼容入口：`PUT /charging-aiot-php/api/monitor-center/monitor.php?action=group`
- 功能：更新监控分组
- 是否鉴权：是

请求体：

```json
{
  "groupId": 1,
  "groupName": "分组A",
  "sort": 1,
  "statusFlag": 1
}
```

返回 `data`：

- `null`

### 3.12 工作台审计

- 主入口：`GET /charging-aiot-php/api/monitor-center/workbench/audit.php`
- 兼容入口：`GET /charging-aiot-php/api/monitor-center/monitor.php?action=workbenchAudit`
- 功能：获取监控中心工作台统计、操作日志、回滚日志、错误日志
- 是否鉴权：是

Query 参数：

- `page`: 操作日志页码
- `pageSize`: 操作日志分页大小
- `limit`: 文件日志读取条数上限
- `startTime`
- `endTime`
- `eventType`: `CREATE/QUERY/UPDATE/MODIFY/DELETE/ROLLBACK/SYNC`
- `keyword`
- `resultStatus`: `0/1`

返回 `data` 结构：

- `stats.groupCount`
- `stats.deviceCount`
- `stats.onlineDeviceCount`
- `stats.pathCount`
- `stats.createCount`
- `stats.queryCount`
- `stats.deleteCount`
- `stats.rollbackCount`
- `stats.errorCount`
- `operationLogs`
- `operationPage.currentPage`
- `operationPage.pageSize`
- `operationPage.total`
- `rollbackLogs`
- `errorLogs`

### 3.13 同步摄像头配置到 MediaMTX

- 主入口：`GET /charging-aiot-php/api/monitor-center/device-center/sync-camera-config.php`
- 兼容入口：`GET /charging-aiot-php/api/monitor-center/monitor.php?action=syncCameraConfig`
- 功能：将数据库中的流路径配置回灌到 MediaMTX
- 是否鉴权：是

返回 `data` 结构：

- `total`
- `successCount`
- `failedCount`
- `skippedCount`
- `failedItems`
- `skippedItems`

### 3.14 MediaMTX 健康状态

- 主入口：`GET /charging-aiot-php/api/monitor-center/device-center/media-mtx-health.php`
- 兼容入口：`GET /charging-aiot-php/api/monitor-center/monitor.php?action=mediaMtxHealth`
- 功能：查询 MediaMTX 自动同步状态和 API 可达性
- 是否鉴权：是

返回 `data` 主要字段：

- `nowTs`
- `nowAt`
- `intervalSeconds`
- `lastAttemptTs`
- `lastAttemptAt`
- `lastSuccessTs`
- `lastSuccessAt`
- `lastFailedCount`
- `nextDueInSeconds`
- `lastError`
- `lastSummary`
- `mediaMtxApiReachable`
- `mediaMtxApiCode`
- `mediaMtxApiMessage`

### 3.15 历史回放列表

- 主入口：`GET /charging-aiot-php/api/monitor-center/video-list/list.php`
- 兼容入口：`GET /charging-aiot-php/api/monitor-center/monitor.php?action=playbackList`
- 功能：扫描录像目录并返回分页回放列表
- 是否鉴权：是

Query 参数：

- `page`
- `pageSize`
- `videoName`: 录像名称关键字
- `startTime`
- `endTime`

返回 `data` 结构：

- `total`
- `records`

`records[*]` 主要字段：

- `id`
- `name`
- `recordTime`
- `coverText`
- `videoUrl`
- `videoPath`
- `fileSize`

## 4. 协议数据 Protocol Data

说明：

- 主目录：`/charging-aiot-php/api/protocol-data/data-center`
- 兼容查询路由：`/charging-aiot-php/api/protocol-data/data-center/stream-query.php?action=...`
- 兼容上传路由：`/charging-aiot-php/api/protocol-data/data-center/upload.php?type=...`

### 4.1 查询 101 协议数据

- 主入口：`POST /charging-aiot-php/api/protocol-data/data-center/query-101.php`
- 兼容入口：`POST /charging-aiot-php/api/protocol-data/data-center/stream-query.php?action=101`
- 功能：查询 `camera_stream_data` 表中的 101 数据
- 是否鉴权：是

请求体：

- `limit`: 返回条数，默认 `20`，最大 `500`
- `cameraId` 或 `camera_id`
- `startTime`
- `endTime`

返回 `data`：

- 数组，每项字段：
  - `id`
  - `msgType`
  - `cameraId`
  - `deviceTimestamp`
  - `payloadData`

### 4.2 查询 102 协议数据

- 主入口：`POST /charging-aiot-php/api/protocol-data/data-center/query-102.php`
- 兼容入口：`POST /charging-aiot-php/api/protocol-data/data-center/stream-query.php?action=102`
- 功能：查询 `camera_stream_data` 表中的 102 数据
- 是否鉴权：是

请求参数与返回结构同 101。

### 4.3 查询 103 协议数据

- 主入口：`POST /charging-aiot-php/api/protocol-data/data-center/query-103.php`
- 兼容入口：`POST /charging-aiot-php/api/protocol-data/data-center/stream-query.php?action=103`
- 功能：查询 `camera_stream_data` 表中的 103 数据
- 是否鉴权：是

请求参数与返回结构同 101。

### 4.4 协议数据分页查询

- 主入口：`GET /charging-aiot-php/api/protocol-data/data-center/page.php`
- 兼容入口：`GET /charging-aiot-php/api/protocol-data/data-center/stream-query.php?action=page`
- 功能：按协议类型分页查询 `camera_stream_data`
- 是否鉴权：是

Query 参数：

- `msgType`: 必填，允许 `101/102/103`
- `page`: 页码
- `pageSize`: 每页条数，最大 `200`
- `cameraId` 或 `camera_id`
- `startTime`
- `endTime`

返回 `data`：

- `total`
- `records`

`records[*]` 字段同 4.1 返回单项。

### 4.5 协议数据统计

- 主入口：`GET /charging-aiot-php/api/protocol-data/data-center/stats.php`
- 兼容入口：`GET /charging-aiot-php/api/protocol-data/data-center/stream-query.php?action=stats`
- 功能：统计在线摄像头数、人数、抓拍数、识别数
- 是否鉴权：是

Query 参数：

- `startTime`
- `endTime`

返回 `data`：

- `onlineDeviceCount`
- `currentTotalPeople`
- `todayCaptureCount`
- `todayRecognizedCount`

### 4.6 新版二进制流上传

- 主入口：`POST /charging-aiot-php/api/protocol-data/data-center/upload-stream.php`
- 功能：接收原始协议二进制流，解析为 101/102/103 帧并写入协议明细表，同时归档原始流和解析产物
- 是否鉴权：否

请求方式：

- Query 参数：
  - `protocol` 或 `proto`: 必填，`101/102/103`
  - `cam_id`: 可选
- Body：
  - 可直接上传二进制流
  - 或使用 `multipart/form-data` 上传 `file`

返回 `data` 结构：

- `protocol`
- `cam_id`
- `frame_count`
- `frames`
- `stored_file`
- `stored_file_size`

`frames[*]` 会根据协议不同返回不同字段：

- 101：
  - `protocol`
  - `cam_id`
  - `timestamp`
  - `frame_seq`
  - `count`
  - `targets`
- 102：
  - `protocol`
  - `cam_id`
  - `timestamp`
  - `frame_seq`
  - `count`
  - `items`
- 103：
  - `protocol`
  - `cam_id`
  - `timestamp`
  - `frame_seq`
  - `base64_image`
  - `count`
  - `payload_type`
  - `track_id`
  - `start_timestamp`
  - `end_timestamp`

### 4.7 旧版上传兼容接口 101

- 主入口：`POST /charging-aiot-php/api/protocol-data/data-center/upload-101.php`
- 兼容入口：`POST /charging-aiot-php/api/protocol-data/data-center/upload.php?type=101`
- 功能：兼容旧版上传，将上传文件内容落入 `camera_stream_data`
- 是否鉴权：否

请求说明：

- `multipart/form-data`
- 必填文件字段：`file`
- 可选：
  - `cameraId`
  - `camera_id`
  - `timestamp`
  - `images` 或 `images[]`

返回 `data`：

- `id`
- `msgType`
- `cameraId`
- `deviceTimestamp`
- `imageCount`

### 4.8 旧版上传兼容接口 102

- 主入口：`POST /charging-aiot-php/api/protocol-data/data-center/upload-102.php`
- 兼容入口：`POST /charging-aiot-php/api/protocol-data/data-center/upload.php?type=102`
- 功能：兼容旧版上传，将上传文件内容落入 `camera_stream_data`
- 是否鉴权：否

请求与返回结构同 4.7。

### 4.9 旧版上传兼容接口 103

- 主入口：`POST /charging-aiot-php/api/protocol-data/data-center/upload-103.php`
- 兼容入口：`POST /charging-aiot-php/api/protocol-data/data-center/upload.php?type=103`
- 功能：兼容旧版上传，将上传文件内容落入 `camera_stream_data`
- 是否鉴权：否

请求与返回结构同 4.7。

### 4.10 协议明细记录查询

- 主入口：`GET /charging-aiot-php/api/protocol-data/data-center/records.php`
- 功能：查询 `message_101_records / message_102_records / message_103_records` 三类明细记录
- 是否鉴权：否

Query 参数：

- `limit`: 默认 `20`，最大 `500`
- `offset`: 默认 `0`
- `protocol`: 可选，`101/102/103`；不传表示全部
- `camera_id` 或 `cam_id`
- `timestamps`: 多个时间戳，逗号或空白分隔
- `start_event_time`
- `end_event_time`
- `include_payload`: `1` 时返回更多负载内容
- `quality_status`: `all/error/missing/normal`

返回 `data` 结构：

- `total`
- `records`
- `linked_packets`
- `protocol_totals`
- `quality_summary`

`records[*]` 公共字段：

- `record_id`
- `cam_id`
- `camera_id`
- `protocol_id`
- `track_id`
- `timestamp`
- `batch_id`
- `source_file_name`
- `source_file_size`
- `create_time`
- `frame_header`
- `frame_tail`
- `crc_value`
- `frame_length`
- `frame_seq`
- `error_message`
- `raw_protocol_hex_preview`
- `raw_protocol_hex_size`
- `normalized_json`
- `details`

`linked_packets[*]` 主要字段：

- `link_key`
- `cam_id`
- `camera_id`
- `timestamp`
- `create_time`
- `protocol_101_record_ids`
- `protocol_102_record_ids`
- `protocol_103_record_ids`
- `target_total`
- `protocols`
- `vector_total`
- `image_total`

`quality_summary` 字段：

- `error_count`
- `missing_count`
- `missing_frames`

### 4.11 查询指定明细帧原始十六进制

- 主入口：`GET /charging-aiot-php/api/protocol-data/data-center/frame.php`
- 功能：查询指定协议记录的原始十六进制帧
- 是否鉴权：否

Query 参数：

- `protocol`: 必填，`101/102/103`
- `record_id`: 必填

返回 `data` 结构：

- `protocol_id`
- `record_id`
- `camera_id`
- `cam_id`
- `timestamp`
- `create_time`
- `raw_protocol_hex`
- `raw_protocol_hex_size`

### 4.12 摄像头选项列表

- 主入口：`GET /charging-aiot-php/api/protocol-data/data-center/cameras.php`
- 功能：查询可用 `camera_id` 选项
- 是否鉴权：否

Query 参数：

- `protocol`: 可选，`101/102/103`

返回 `data` 结构：

- `records`

`records[*]` 字段：

- `camera_id`
- `cam_id`
- `label`
- `value`

## 5. 信息管理 Information Management

说明：

- 人员模块统一服务文件：`/charging-aiot-php/api/information-management/common/personnel_service.php`
- 存储模块统一服务文件：`/charging-aiot-php/api/information-management/common/storage_settings_service.php`

### 5.1 人员分页

- 主入口：`GET /charging-aiot-php/api/information-management/personnel/page.php`
- 功能：分页查询用户列表
- 是否鉴权：是

Query 参数：

- `page`: 默认 `1`
- `pageSize`: 默认 `10`，最大 `100`
- `role`: 角色过滤，可传 `1~4` 或角色字符串
- `keyword`: 按 `username/nickname/tel` 模糊查询

返回 `data` 结构：

- `total`
- `records`

`records[*]` 字段：

- `user_id`
- `user__uuid`
- `username`
- `nickname`
- `role`
- `tel`
- `email`
- `createtime`

### 5.2 新增人员

- 主入口：`POST /charging-aiot-php/api/information-management/personnel/create.php`
- 功能：新增用户
- 是否鉴权：是，仅角色 `1/2` 可操作

请求体：

```json
{
  "username": "operator01",
  "password": "123456",
  "nickname": "运维A",
  "role": 3,
  "tel": "13800000000",
  "email": "operator@example.com"
}
```

返回 `data`：

- `user_id`

### 5.3 更新人员

- 主入口：`PUT /charging-aiot-php/api/information-management/personnel/update.php`
- 功能：更新用户
- 是否鉴权：是，仅角色 `1/2` 可操作

请求体主要字段：

- `user_id`: 必填
- `username`: 必填
- `role`: 必填
- `nickname`
- `tel`
- `email`
- `password`: 可选；不传表示不改密码

返回 `data`：

- `null`

### 5.4 删除人员

- 主入口：`DELETE /charging-aiot-php/api/information-management/personnel/delete.php`
- 功能：删除用户
- 是否鉴权：是，仅角色 `1/2` 可操作

请求体：

```json
{
  "user_id": 2
}
```

返回 `data`：

- `null`

### 5.5 存储路径设置列表

- 主入口：`GET /charging-aiot-php/api/information-management/storage/list.php`
- 功能：查询文件存储路径模板定义
- 是否鉴权：是

返回 `data` 结构：

- `records`

`records[*]` 字段：

- `category_key`
- `name`
- `description`
- `path_template`
- `example_path`

### 5.6 更新存储路径设置

- 主入口：`PUT /charging-aiot-php/api/information-management/storage/update.php`
- 功能：更新存储路径模板，并可选执行历史文件迁移
- 是否鉴权：是，仅角色 `1/2` 可操作

请求体：

```json
{
  "settings": [
    {
      "category_key": "payload",
      "path_template": "storage/{date}/{protocol}/{camera}/payload"
    },
    {
      "category_key": "embedding",
      "path_template": "storage/{date}/{protocol}/{camera}/embedding/batch_{batch}"
    }
  ],
  "run_migration": 1
}
```

请求字段说明：

- `settings`: 必填，数组
- `settings[].category_key`: 分类键，如 `raw_upload/frame/payload/image/embedding`
- `settings[].path_template`: 相对 `charging-aiot-php` 的路径模板
- `run_migration`: 是否执行历史文件迁移，`1` 执行，`0` 不执行

返回 `data` 结构：

- `records`: 最新路径模板列表
- `migration`: 迁移结果；若未执行迁移则可能为 `null`

`migration` 字段：

- `updated_rows`
- `moved_files`
- `missing_files`
- `errors`
