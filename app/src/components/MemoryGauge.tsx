import { useQuery } from '@tanstack/react-query';

interface SystemStats {
  cpu_cores: any[];
  core_count: number;
  global_cpu_usage: number;
  total_memory: number;
  used_memory: number;
  timestamp: string;
}

export function MemoryGauge() {
  const { data: systemStats, isLoading, error } = useQuery<SystemStats>({
    queryKey: ['memory-stats'],
    queryFn: async () => {
      const response = await fetch('/system', {
        headers: {
          'Accept': 'application/json'
        }
      });
      if (!response.ok) {
        throw new Error(`Failed to fetch system stats: ${response.status}`);
      }
      return response.json();
    },
    enabled: true, // Always enabled since system endpoint doesn't require auth
    refetchInterval: 2000, // Update every 2 seconds
    retry: (failureCount, err: any) => {
      return failureCount < 2;
    }
  });

  if (isLoading || !systemStats) {
    return (
      <div className="flex items-center justify-center h-32">
        <div className="text-sm text-gray-500">Loading memory stats...</div>
      </div>
    );
  }

  const totalMemory = systemStats.total_memory;
  const usedMemory = systemStats.used_memory;
  const freeMemory = totalMemory - usedMemory;
  const usagePercentage = Math.round((usedMemory / totalMemory) * 100);

  // Gauge configuration
  const size = 120;
  const strokeWidth = 8;
  const radius = (size - strokeWidth) / 2;
  const circumference = radius * 2 * Math.PI;
  const strokeDasharray = circumference;
  const strokeDashoffset = circumference - (usagePercentage / 100) * circumference;

  // Color based on usage percentage
  const getColor = (percentage: number) => {
    if (percentage < 60) return '#10b981'; // Green
    if (percentage < 80) return '#f59e0b'; // Amber
    return '#ef4444'; // Red
  };

  const gaugeColor = getColor(usagePercentage);

  // Format bytes to human readable
  const formatBytes = (bytes: number) => {
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let size = bytes;
    let unitIndex = 0;
    
    while (size >= 1024 && unitIndex < units.length - 1) {
      size /= 1024;
      unitIndex++;
    }
    
    return `${size.toFixed(1)} ${units[unitIndex]}`;
  };

  return (
    <div className="bg-white rounded-xl p-6 shadow-sm">
      <h3 className="text-sm font-medium text-gray-600 mb-4">Memory Usage</h3>
      
      <div className="flex flex-col items-center">
        {/* Circular Gauge */}
        <div className="relative mb-4">
          <svg width={size} height={size} className="transform -rotate-90">
            {/* Background circle */}
            <circle
              cx={size / 2}
              cy={size / 2}
              r={radius}
              fill="none"
              stroke="#e5e7eb"
              strokeWidth={strokeWidth}
            />
            {/* Progress circle */}
            <circle
              cx={size / 2}
              cy={size / 2}
              r={radius}
              fill="none"
              stroke={gaugeColor}
              strokeWidth={strokeWidth}
              strokeLinecap="round"
              strokeDasharray={strokeDasharray}
              strokeDashoffset={strokeDashoffset}
              className="transition-all duration-500 ease-in-out"
            />
          </svg>
          {/* Center text */}
          <div className="absolute inset-0 flex flex-col items-center justify-center">
            <div className="text-2xl font-bold text-gray-800">
              {usagePercentage}%
            </div>
            <div className="text-xs text-gray-500">Memory</div>
          </div>
        </div>

        {/* Memory Details */}
        <div className="w-full space-y-2 text-xs">
          <div className="flex justify-between items-center">
            <span className="text-gray-600">Used:</span>
            <span className="font-medium text-gray-800">{formatBytes(usedMemory)}</span>
          </div>
          <div className="flex justify-between items-center">
            <span className="text-gray-600">Free:</span>
            <span className="font-medium text-gray-800">{formatBytes(freeMemory)}</span>
          </div>
          <div className="flex justify-between items-center border-t pt-2">
            <span className="text-gray-600">Total:</span>
            <span className="font-semibold text-gray-800">{formatBytes(totalMemory)}</span>
          </div>
        </div>

        {/* Status indicator */}
        <div className="mt-3 text-xs">
          <div className={`inline-flex items-center px-2 py-1 rounded-full ${
            usagePercentage < 60 
              ? 'bg-green-100 text-green-800' 
              : usagePercentage < 80 
                ? 'bg-amber-100 text-amber-800' 
                : 'bg-red-100 text-red-800'
          }`}>
            <div className={`w-2 h-2 rounded-full mr-2 ${
              usagePercentage < 60 
                ? 'bg-green-500' 
                : usagePercentage < 80 
                  ? 'bg-amber-500' 
                  : 'bg-red-500'
            }`}></div>
            {usagePercentage < 60 ? 'Normal' : usagePercentage < 80 ? 'Warning' : 'Critical'}
          </div>
        </div>
      </div>
    </div>
  );
}