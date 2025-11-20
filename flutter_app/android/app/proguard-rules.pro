# Flutter & Firebase keep rules
-keep class io.flutter.embedding.** { *; }
-keep class com.google.firebase.messaging.** { *; }
-keep class com.google.firebase.iid.** { *; }
-dontwarn javax.annotation.**
# Keep models used via reflection (add your own if needed)
