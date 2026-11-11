#include "notify.h"
#include <string.h>
#include <stdio.h>
#include <unistd.h>

/* Orbis library declarations for dynamic symbol resolution */
typedef int (*sceKernelSendNotificationRequest_t)(int type, SceNotificationRequest *req, size_t size, int blocking);
static sceKernelSendNotificationRequest_t p_sceKernelSendNotificationRequest = NULL;

#if defined(__ORBIS__) || defined(__PS4__)
#include <kernel.h>
#endif

bool notify_init(void) {
#if defined(__ORBIS__) || defined(__PS4__)
    int libSysmodule = sceKernelLoadStartModule("/system/common/lib/libSceSysmodule.sprx", 0, NULL, 0, NULL, NULL);
    (void)libSysmodule;

    int libNotification = sceKernelLoadStartModule("/system/common/lib/libSceNotificationRequest.sprx", 0, NULL, 0, NULL, NULL);
    if (libNotification > 0) {
        sceKernelDlsym(libNotification, "sceKernelSendNotificationRequest", (void **)&p_sceKernelSendNotificationRequest);
    }
#endif
    return true;
}

int notify_show(const char *message, const char *icon_uri) {
    if (!message || strlen(message) == 0) {
        return -1;
    }

    SceNotificationRequest req;
    memset(&req, 0, sizeof(SceNotificationRequest));

    req.type = 0;
    req.reqId = 0;
    req.priority = 0;
    req.msgId = 0;
    req.targetId = -1;
    req.userId = -1;

    if (icon_uri && strlen(icon_uri) > 0) {
        strncpy(req.iconUri, icon_uri, sizeof(req.iconUri) - 1);
    } else {
        strncpy(req.iconUri, "cxml://psnotification/tex_icon_system", sizeof(req.iconUri) - 1);
    }

    // Safely copy message, truncating to buffer limit
    strncpy(req.message, message, sizeof(req.message) - 1);
    req.message[sizeof(req.message) - 1] = '\0';

    if (p_sceKernelSendNotificationRequest != NULL) {
        return p_sceKernelSendNotificationRequest(0, &req, sizeof(SceNotificationRequest), 0);
    }

    // Fallback console logging in simulation or development build
    printf("[SugengRawuhNotify] PS4 POPUP: %s\n", req.message);
    return 0;
}

void notify_cleanup(void) {
    p_sceKernelSendNotificationRequest = NULL;
}
