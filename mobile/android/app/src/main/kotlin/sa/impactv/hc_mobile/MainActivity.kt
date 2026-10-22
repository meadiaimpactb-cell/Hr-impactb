package sa.impactv.hc_mobile

import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.provider.Settings
import androidx.core.content.FileProvider
import io.flutter.embedding.android.FlutterFragmentActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel
import java.io.File
import java.net.HttpURLConnection
import java.net.URL

/**
 * In-app update (Android): downloads the APK published on the platform into the app's cache and hands it to the
 * system installer, which installs it over the current app (same signing identity → the app and its data stay).
 */
class MainActivity : FlutterFragmentActivity() {
    private val main = Handler(Looper.getMainLooper())

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        val channel = MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "hc/update")
        channel.setMethodCallHandler { call, result ->
            when (call.method) {
                // Android 8+: the user allows "install unknown apps" for HC once
                "canInstall" -> result.success(Build.VERSION.SDK_INT < Build.VERSION_CODES.O || packageManager.canRequestPackageInstalls())
                "allowInstall" -> {
                    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                        startActivity(Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:$packageName")))
                    }
                    result.success(null)
                }
                "download" -> {
                    val url = call.argument<String>("url")
                    if (url == null) {
                        result.error("url", "missing url", null)
                    } else {
                        Thread {
                            try {
                                val dir = File(cacheDir, "updates").apply { mkdirs() }
                                val out = File(dir, "HC-update.apk")
                                val conn = URL(url).openConnection() as HttpURLConnection
                                conn.connectTimeout = 20000
                                conn.readTimeout = 60000
                                conn.instanceFollowRedirects = true
                                if (conn.responseCode !in 200..299) throw IllegalStateException("HTTP ${conn.responseCode}")
                                val total = conn.contentLengthLong
                                conn.inputStream.use { input ->
                                    out.outputStream().use { output ->
                                        val buf = ByteArray(64 * 1024)
                                        var done = 0L
                                        var last = -2
                                        while (true) {
                                            val n = input.read(buf)
                                            if (n < 0) break
                                            output.write(buf, 0, n)
                                            done += n
                                            val pct = if (total > 0) (done * 100 / total).toInt() else -1
                                            if (pct != last) {
                                                last = pct
                                                main.post { channel.invokeMethod("progress", pct) }
                                            }
                                        }
                                    }
                                }
                                main.post { result.success(out.absolutePath) }
                            } catch (e: Exception) {
                                main.post { result.error("download", e.message, null) }
                            }
                        }.start()
                    }
                }
                "install" -> {
                    val path = call.argument<String>("path")
                    if (path == null) {
                        result.error("path", "missing path", null)
                    } else {
                        val uri = FileProvider.getUriForFile(this, "$packageName.update", File(path))
                        startActivity(Intent(Intent.ACTION_VIEW).apply {
                            setDataAndType(uri, "application/vnd.android.package-archive")
                            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK)
                        })
                        result.success(true)
                    }
                }
                else -> result.notImplemented()
            }
        }
    }
}
