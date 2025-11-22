# Flutter & Firebase keep rules
-keep class io.flutter.embedding.** { *; }
-keep class com.google.firebase.messaging.** { *; }
-keep class com.google.firebase.iid.** { *; }
-dontwarn javax.annotation.**

# Google Play Core - keep all classes to avoid R8 errors
-keep class com.google.android.play.core.** { *; }
-dontwarn com.google.android.play.core.**

# Keep models used via reflection (add your own if needed)
