try {
  importScripts("https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.sw.js");
} catch(e) {
  // Retry without query params if called with appId param
  console.warn("OneSignal SW importScripts failed:", e);
}
