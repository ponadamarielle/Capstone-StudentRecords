param([string]$Heartbeat, [string]$Running, [string]$Folder = '')

$log = Join-Path ([System.IO.Path]::GetTempPath()) 'pup_scan_dialog.log'
function Write-Log([string]$message) {
    try { Add-Content -LiteralPath $log -Value ((Get-Date -Format 'yyyy-MM-dd HH:mm:ss') + ' ' + $message) } catch {}
}

try {
    Add-Type -TypeDefinition @"
using System;
using System.Text;
using System.Threading;
using System.Collections.Generic;
using System.Runtime.InteropServices;

public static class PupScanDialog
{
    public delegate bool EnumProc(IntPtr hWnd, IntPtr lParam);

    [DllImport("user32.dll")] static extern bool EnumWindows(EnumProc callback, IntPtr lParam);
    [DllImport("user32.dll")] static extern bool EnumChildWindows(IntPtr parent, EnumProc callback, IntPtr lParam);
    [DllImport("user32.dll", CharSet = CharSet.Unicode)] static extern int GetWindowText(IntPtr hWnd, StringBuilder text, int max);
    [DllImport("user32.dll", CharSet = CharSet.Unicode)] static extern int GetClassName(IntPtr hWnd, StringBuilder text, int max);
    [DllImport("user32.dll", CharSet = CharSet.Unicode)] static extern IntPtr SendMessageTimeout(IntPtr hWnd, uint msg, IntPtr wParam, StringBuilder lParam, uint flags, uint timeout, out IntPtr result);
    [DllImport("user32.dll")] static extern bool IsWindowVisible(IntPtr hWnd);
    [DllImport("user32.dll")] static extern bool ShowWindow(IntPtr hWnd, int command);
    [DllImport("user32.dll")] static extern bool PostMessage(IntPtr hWnd, uint msg, IntPtr wParam, IntPtr lParam);

    public delegate void WinEventProc(IntPtr hook, uint eventType, IntPtr hWnd, int idObject, int idChild, uint thread, uint time);
    [StructLayout(LayoutKind.Sequential)] public struct MSG { public IntPtr hwnd; public uint message; public IntPtr wParam; public IntPtr lParam; public uint time; public int x; public int y; }
    [DllImport("user32.dll")] static extern IntPtr SetWinEventHook(uint eventMin, uint eventMax, IntPtr module, WinEventProc callback, uint process, uint thread, uint flags);
    [DllImport("user32.dll")] static extern bool UnhookWinEvent(IntPtr hook);
    [DllImport("user32.dll")] static extern int GetMessage(out MSG msg, IntPtr hWnd, uint min, uint max);
    [DllImport("user32.dll")] static extern bool TranslateMessage(ref MSG msg);
    [DllImport("user32.dll")] static extern IntPtr DispatchMessage(ref MSG msg);
    [DllImport("user32.dll")] static extern int GetWindowLong(IntPtr hWnd, int index);
    [DllImport("user32.dll")] static extern int SetWindowLong(IntPtr hWnd, int index, int value);
    [DllImport("user32.dll")] static extern bool SetLayeredWindowAttributes(IntPtr hWnd, uint key, byte alpha, uint flags);

    const int GWL_EXSTYLE = -20;
    const int WS_EX_LAYERED = 0x80000;
    static WinEventProc hookProc;
    static string watchLeaf = "";
    static readonly Dictionary<IntPtr, DateTime> pending = new Dictionary<IntPtr, DateTime>();
    static readonly Dictionary<IntPtr, bool> wasLayered = new Dictionary<IntPtr, bool>();
    static readonly object gate = new object();
    public static int SilentlyClosed = 0;

    static bool TitleMatches(string title, string leaf)
    {
        title = title.Trim();
        return title.Length > 0 && (String.Equals(title, leaf, StringComparison.OrdinalIgnoreCase)
            || title.StartsWith(leaf + " - ", StringComparison.OrdinalIgnoreCase)
            || title.EndsWith("\\" + leaf, StringComparison.OrdinalIgnoreCase));
    }

    static void MakeInvisible(IntPtr hWnd)
    {
        int style = GetWindowLong(hWnd, GWL_EXSTYLE);
        wasLayered[hWnd] = (style & WS_EX_LAYERED) != 0;
        SetWindowLong(hWnd, GWL_EXSTYLE, style | WS_EX_LAYERED);
        SetLayeredWindowAttributes(hWnd, 0, 0, 2);
    }

    static void MakeVisible(IntPtr hWnd)
    {
        SetLayeredWindowAttributes(hWnd, 0, 255, 2);
        bool layered;
        if (wasLayered.TryGetValue(hWnd, out layered) && !layered)
        {
            SetWindowLong(hWnd, GWL_EXSTYLE, GetWindowLong(hWnd, GWL_EXSTYLE) & ~WS_EX_LAYERED);
        }
        wasLayered.Remove(hWnd);
    }

    static void CloseSilently(IntPtr hWnd)
    {
        ShowWindow(hWnd, 0);
        PostMessage(hWnd, 0x0010, IntPtr.Zero, IntPtr.Zero);
        pending.Remove(hWnd);
        wasLayered.Remove(hWnd);
        SilentlyClosed++;
    }

    static void OnWinEvent(IntPtr hook, uint eventType, IntPtr hWnd, int idObject, int idChild, uint thread, uint time)
    {
        if (idObject != 0 || hWnd == IntPtr.Zero) return;
        lock (gate)
        {
            if (watchLeaf == "" || ClassOf(hWnd) != "CabinetWClass") return;
            string title = Caption(hWnd);
            if (TitleMatches(title, watchLeaf))
            {
                CloseSilently(hWnd);
                return;
            }
            if (eventType == 0x8000 || eventType == 0x8002)
            {
                if (!pending.ContainsKey(hWnd))
                {
                    MakeInvisible(hWnd);
                    pending[hWnd] = DateTime.UtcNow;
                }
            }
            else if (eventType == 0x800C && pending.ContainsKey(hWnd) && title.Trim().Length > 0)
            {
                MakeVisible(hWnd);
                pending.Remove(hWnd);
            }
        }
    }

    public static void StartSilentWatch(string leaf)
    {
        watchLeaf = leaf ?? "";
        if (watchLeaf == "") return;
        Thread worker = new Thread(delegate ()
        {
            hookProc = OnWinEvent;
            IntPtr hook = SetWinEventHook(0x8000, 0x800C, IntPtr.Zero, hookProc, 0, 0, 0x0002);
            MSG msg;
            while (GetMessage(out msg, IntPtr.Zero, 0, 0) > 0)
            {
                TranslateMessage(ref msg);
                DispatchMessage(ref msg);
            }
            UnhookWinEvent(hook);
        });
        worker.IsBackground = true;
        worker.Start();
    }

    public static void RestoreAll()
    {
        lock (gate)
        {
            watchLeaf = "";
            foreach (IntPtr hWnd in new List<IntPtr>(pending.Keys))
            {
                MakeVisible(hWnd);
            }
            pending.Clear();
        }
    }

    public static void RestoreStale()
    {
        lock (gate)
        {
            List<IntPtr> stale = new List<IntPtr>();
            foreach (KeyValuePair<IntPtr, DateTime> entry in pending)
            {
                if ((DateTime.UtcNow - entry.Value).TotalMilliseconds > 1500) stale.Add(entry.Key);
            }
            foreach (IntPtr hWnd in stale)
            {
                if (TitleMatches(Caption(hWnd), watchLeaf))
                {
                    CloseSilently(hWnd);
                }
                else
                {
                    MakeVisible(hWnd);
                    pending.Remove(hWnd);
                }
            }
        }
    }

    public static int CloseFolder(string leaf)
    {
        int closed = 0;
        if (String.IsNullOrEmpty(leaf)) return 0;
        EnumWindows(delegate (IntPtr hWnd, IntPtr lParam)
        {
            if (ClassOf(hWnd) != "CabinetWClass") return true;
            string title = Caption(hWnd).Trim();
            if (String.Equals(title, leaf, StringComparison.OrdinalIgnoreCase)
                || title.EndsWith("\\" + leaf, StringComparison.OrdinalIgnoreCase))
            {
                ShowWindow(hWnd, 0);
                PostMessage(hWnd, 0x0010, IntPtr.Zero, IntPtr.Zero);
                closed++;
            }
            return true;
        }, IntPtr.Zero);
        return closed;
    }

    static string Caption(IntPtr hWnd)
    {
        StringBuilder text = new StringBuilder(512);
        GetWindowText(hWnd, text, 512);
        return text.ToString();
    }

    static string ClassOf(IntPtr hWnd)
    {
        StringBuilder text = new StringBuilder(256);
        GetClassName(hWnd, text, 256);
        return text.ToString();
    }

    static string ControlText(IntPtr hWnd)
    {
        StringBuilder text = new StringBuilder(512);
        IntPtr result;
        SendMessageTimeout(hWnd, 0x000D, (IntPtr) 512, text, 0x0002, 200, out result);
        return text.ToString();
    }

    public static int HideProgress()
    {
        int hidden = 0;
        EnumWindows(delegate (IntPtr hWnd, IntPtr lParam)
        {
            if (!IsWindowVisible(hWnd) || Caption(hWnd).Trim() != "Scan") return true;
            bool match = false;
            EnumChildWindows(hWnd, delegate (IntPtr child, IntPtr childParam)
            {
                string value = ControlText(child);
                if (ClassOf(child) == "msctls_progress32"
                    || value.IndexOf("Transferring image data", StringComparison.OrdinalIgnoreCase) >= 0
                    || value.IndexOf("button to abort", StringComparison.OrdinalIgnoreCase) >= 0)
                {
                    match = true;
                    return false;
                }
                return true;
            }, IntPtr.Zero);
            if (match)
            {
                ShowWindow(hWnd, 0);
                hidden++;
            }
            return true;
        }, IntPtr.Zero);
        return hidden;
    }
}
"@
    Write-Log 'Scanner dialog hider started.'
} catch {
    Write-Log ('Could not start the scanner dialog hider: ' + $_.Exception.Message)
    Remove-Item -LiteralPath $Running -ErrorAction SilentlyContinue
    exit
}

$shell = $null
if ($Folder -ne '') {
    try { $shell = New-Object -ComObject Shell.Application } catch { Write-Log ('Could not watch the scan folder windows: ' + $_.Exception.Message) }
}
$folderKey = $Folder.TrimEnd('\').ToLower()
$folderLeaf = if ($Folder -ne '') { Split-Path -Leaf $Folder.TrimEnd('\') } else { '' }
$tick = 0
if ($folderLeaf -ne '') {
    try { [PupScanDialog]::StartSilentWatch($folderLeaf) } catch { Write-Log ('Could not start the silent folder watch: ' + $_.Exception.Message) }
}
$silentLogged = 0

while ($true) {
    if (-not (Test-Path -LiteralPath $Heartbeat)) { break }
    $age = (Get-Date) - (Get-Item -LiteralPath $Heartbeat).LastWriteTime
    if ($age.TotalSeconds -gt 20) { break }
    if (($tick % 20) -eq 0) {
        try { (Get-Item -LiteralPath $Running).LastWriteTime = Get-Date } catch { New-Item -ItemType File -Path $Running -Force | Out-Null }
    }
    try {
        $count = [PupScanDialog]::HideProgress()
        if ($count -gt 0) { Write-Log ('Hid ' + $count + ' scanner progress window(s).') }
    } catch {
        Write-Log ('Hide failed: ' + $_.Exception.Message)
    }
    $tick++
    try { [PupScanDialog]::RestoreStale() } catch {}
    if ([PupScanDialog]::SilentlyClosed -gt $silentLogged) {
        Write-Log ('Closed ' + ([PupScanDialog]::SilentlyClosed - $silentLogged) + ' scan folder window(s) before they appeared.')
        $silentLogged = [PupScanDialog]::SilentlyClosed
    }
    if ($folderLeaf -ne '') {
        try {
            $closedNow = [PupScanDialog]::CloseFolder($folderLeaf)
            if ($closedNow -gt 0) { Write-Log ('Closed ' + $closedNow + ' scan folder window(s) right away.') }
        } catch {}
    }
    if ($shell -ne $null -and ($tick % 10) -eq 0) {
        try {
            foreach ($window in @($shell.Windows())) {
                try {
                    $path = $window.Document.Folder.Self.Path
                    if ($path -and $path.TrimEnd('\').ToLower().StartsWith($folderKey)) {
                        $window.Quit()
                        Write-Log ('Closed the scan folder window: ' + $path)
                    }
                } catch {}
            }
        } catch {}
    }
    Start-Sleep -Milliseconds 50
}
try { [PupScanDialog]::RestoreAll() } catch {}
Remove-Item -LiteralPath $Running -ErrorAction SilentlyContinue
Write-Log 'Scanner dialog hider stopped.'
