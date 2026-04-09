# 🚀 UniFi MiniDash Update v1.5.0

Major update focusing on system observability and UniFi Protect dashboard flexibility.

## 📋 Changelog

### 🛡️ System Logs (`logs.php`)

Replaced legacy file-based log parsing with a robust **API-driven Event System**.

- **Direct API Integration**: Fetches events and alarms directly from the UniFi Controller API (`/proxy/network/api/s/default/stat/event`).
- **Unified Timeline**: Merges standard Events and Security Alarms into a single chronological view.
- **Smart Severity Detection**: Automatically categorizes events as `INFO`, `WARNING`, `ERROR`, or `CRITICAL` based on event keys (e.g., Firewall Blocks, Threat Detections, Device Disconnections).
- **Advanced Filtering**: Added a dropdown filter to view logs by severity level.
- **Dynamic Pagination**:
  - Persists filter state across pages.
  - Added manageable page sizes: 25, 50, 100, and **500** entries per page.
- **UI Enhancements**:
  - New glassmorphism table design.
  - Color-coded badges for severity (Red/Rose for Critical, Amber for Warning, etc.).
  - Detailed modal view for inspecting raw event JSON and properties.

### 🎥 UniFi Protect Dashboard (`protect.php`)

Complete overhaul of the camera grid system to support custom layouts.

- **Dynamic Grid Layout**: New toolbar allows switching between 1, 2, 4, 9, and 12 camera views instantly.
- **Interactive Slots**: Click any empty slot to select a camera source from the available list.
- **Status Tiles**:
  - **NVR Status**: Shows storage usage, uptime, and healthy/recording indicators.
  - **Traffic/Bandwidth**: Real-time camera bandwidth usage.
  - **Connections**: Counter for online/offline cameras.

### 🔧 General Improvements

- **Layout Standardization**: Aligned main dashboards to `max-w-7xl` for consistent visual weight on large screens.
- **Cleanups**: Removed direct file access references configuration in `logs.php` (security/stability improvement).
- **Navigation**: Added "Logs" to the global navigation menu.

---

_Created by Antigravity Assistant_
