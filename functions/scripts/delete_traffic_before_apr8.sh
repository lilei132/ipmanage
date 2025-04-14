#!/bin/bash

# 删除4月8日前的端口流量数据
# 该脚本将调用cleanup_traffic_data.php脚本，并传入2023-04-08日期参数

# 设置脚本路径
SCRIPT_DIR=$(dirname "$0")
CLEANUP_SCRIPT="$SCRIPT_DIR/cleanup_traffic_data.php"

# 确认脚本存在
if [ ! -f "$CLEANUP_SCRIPT" ]; then
    echo "错误: 找不到清理脚本 $CLEANUP_SCRIPT"
    exit 1
fi

# 提示用户确认
echo "此操作将删除所有2023年4月8日之前的端口流量数据。"
echo "数据删除后将无法恢复。"
read -p "是否继续? (y/n): " confirm

if [ "$confirm" != "y" ] && [ "$confirm" != "Y" ]; then
    echo "操作已取消"
    exit 0
fi

# 执行清理命令
echo "开始删除4月8日前的流量数据..."
php "$CLEANUP_SCRIPT" --date=2023-04-08

# 检查执行结果
if [ $? -eq 0 ]; then
    echo "删除操作已成功完成"
else
    echo "删除操作失败，请检查错误信息"
    exit 1
fi

exit 0 