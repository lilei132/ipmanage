<?php
// 设置相应头
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// 获取参数
$deviceId = isset($_REQUEST['deviceId']) ? $_REQUEST['deviceId'] : 1;
$interfaceId = isset($_REQUEST['interfaceId']) ? $_REQUEST['interfaceId'] : 1;
$timespan = isset($_REQUEST['timespan']) ? $_REQUEST['timespan'] : '7d';

// 生成测试数据
function generateData($timespan) {
    $inData = [];
    $outData = [];
    $now = time() * 1000; // 当前时间戳（毫秒）
    
    $dataPoints = 60;
    $interval = 60 * 1000; // 60秒
    
    // 根据时间跨度设置数据点间隔和数量
    if ($timespan == '1h') {
        $dataPoints = 60; // 1分钟一个点
        $interval = 60 * 1000;
    } else if ($timespan == '1d') {
        $dataPoints = 288; // 5分钟一个点
        $interval = 5 * 60 * 1000;
    } else if ($timespan == '7d') {
        $dataPoints = 168; // 1小时一个点
        $interval = 60 * 60 * 1000;
    } else {
        $dataPoints = 720; // 1小时一个点
        $interval = 60 * 60 * 1000;
    }
    
    // 生成正弦波形数据 - 确保平滑曲线
    for ($i = $dataPoints - 1; $i >= 0; $i--) {
        $time = $now - ($i * $interval);
        
        // 基础流量值 (100 Mbps)
        $base = 100000000;
        // 振幅 (50 Mbps)
        $amplitude = 50000000;
        // 周期
        $period = $dataPoints / 5;
        
        // 入流量 - 正弦波
        $inValue = $base + $amplitude * sin(2 * M_PI * ($i / $period));
        
        // 出流量 - 余弦波，略低于入流量
        $outValue = ($base * 0.7) + ($amplitude * 0.8) * cos(2 * M_PI * ($i / $period));
        
        // 添加少量随机波动
        $inValue += rand(-5000000, 5000000);
        $outValue += rand(-4000000, 4000000);
        
        // 确保值不为负
        $inValue = max(0, $inValue);
        $outValue = max(0, $outValue);
        
        $inData[] = [$time, $inValue];
        $outData[] = [$time, $outValue];
    }
    
    return ['in_data' => $inData, 'out_data' => $outData];
}

// 获取测试数据
$data = generateData($timespan);

// 返回数据
$response = [
    'success' => true,
    'message' => '使用修复后的平滑测试数据',
    'count' => count($data['in_data']),
    'in_data' => $data['in_data'],
    'out_data' => $data['out_data'],
    'speed' => 1000000000, // 1Gbps
    'deviceId' => $deviceId,
    'interfaceId' => $interfaceId,
    'timespan' => $timespan,
    'data_source' => 'fixed_test_data'
];

echo json_encode($response); 