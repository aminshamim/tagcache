import { useQuery } from '@tanstack/react-query';

interface CpuCore {
  core: number;
  name: string;
  usage: number;
  frequency: number;
}

interface SystemStats {
  cpu_cores: CpuCore[];
  core_count: number;
  global_cpu_usage: number;
  total_memory: number;
  used_memory: number;
  timestamp: string;
}

export function CpuGauge() {
  const { data: systemStats, isLoading, error } = useQuery<SystemStats>({
    queryKey: ['system-stats'],
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

  if (isLoading) {
    return (
      <div className="text-xs text-gray-500 text-center py-4">
        Loading CPU stats...
      </div>
    );
  }

  if (error) {
    return (
      <div className="text-xs text-red-500 text-center py-4">
        Failed to load CPU stats
      </div>
    );
  }

  if (!systemStats || !systemStats.cpu_cores) {
    return (
      <div className="text-xs text-gray-400 text-center py-4">
        No CPU data available
      </div>
    );
  }

  const cpuUsage = systemStats.global_cpu_usage;

  // Color based on usage level
  const getColor = (usage: number) => {
    if (usage < 30) return '#10b981'; // green
    if (usage < 60) return '#f59e0b'; // amber
    if (usage < 80) return '#f97316'; // orange
    return '#ef4444'; // red
  };

  const color = getColor(cpuUsage);
  const size = 80;
  const radius = (size - 12) / 2;
  const circumference = 2 * Math.PI * radius;
  const strokeDasharray = circumference;
  const strokeDashoffset = circumference - (cpuUsage / 100) * circumference;

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h4 className="text-sm font-medium text-gray-700">CPU Usage</h4>
        <div className="text-xs text-gray-500">
          {systemStats.core_count} cores
        </div>
      </div>
      
      {/* Overall CPU usage gauge */}
      <div className="flex flex-col items-center space-y-3">
        <div className="relative flex flex-col items-center" style={{ width: 80, height: 104 }}>
          <svg width={80} height={80} className="transform -rotate-90">
            {/* Background circle */}
            <circle
              cx={40}
              cy={40}
              r={32}
              stroke="#e5e7eb"
              strokeWidth="6"
              fill="transparent"
            />
            {/* Usage arc */}
            <circle
              cx={40}
              cy={40}
              r={32}
              stroke={color}
              strokeWidth="6"
              fill="transparent"
              strokeDasharray={strokeDasharray}
              strokeDashoffset={strokeDashoffset}
              strokeLinecap="round"
              className="transition-all duration-500 ease-out"
            />
          </svg>
          {/* Center text */}
          <div className="absolute inset-0 flex flex-col items-center justify-center">
            <div className="font-mono font-bold text-lg text-gray-800">{systemStats.global_cpu_usage.toFixed(0)}%</div>
            <div className="text-xs text-gray-500">Overall</div>
          </div>
        </div>
        
        {/* Individual core bars */}
        <div className="w-full space-y-1">
          <div className="text-xs text-gray-600 mb-2">Per Core:</div>
          {systemStats.cpu_cores.map(core => {
            const coreColor = core.usage < 30 ? '#10b981' : 
                             core.usage < 60 ? '#f59e0b' : 
                             core.usage < 80 ? '#f97316' : '#ef4444';
            
            return (
              <div key={core.core} className="flex items-center gap-2">
                <span className="text-xs text-gray-500 w-8 text-right">C{core.core}</span>
                <div className="flex-1 h-2 bg-gray-200 rounded-full overflow-hidden">
                  <div 
                    className="h-full transition-all duration-500 ease-out rounded-full"
                    style={{ 
                      width: `${core.usage}%`,
                      backgroundColor: coreColor
                    }}
                  />
                </div>
                <span className="text-xs text-gray-600 w-10 text-right font-mono">
                  {core.usage.toFixed(0)}%
                </span>
              </div>
            );
          })}
        </div>
      </div>
    </div>
  );
}