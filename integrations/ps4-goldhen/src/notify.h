#ifndef SUGENGRAWUH_NOTIFY_H
#define SUGENGRAWUH_NOTIFY_H

#include <stdint.h>
#include <stdbool.h>

#ifdef __cplusplus
extern "C" {
#endif

#define NOTIFY_MAX_MESSAGE_LEN 256
#define NOTIFY_ICON_URI_MAX 256

/**
 * PS4 Orbis OS Native Notification Structure
 * Compatible with GoldHEN Plugin SDK & OpenOrbis sceKernelSendNotificationRequest
 */
typedef struct SceNotificationRequest {
    int32_t type;
    int32_t reqId;
    int32_t priority;
    int32_t msgId;
    int32_t targetId;
    int32_t userId;
    int32_t unk1;
    int32_t unk2;
    int32_t unk3;
    int32_t unk4;
    char iconUri[NOTIFY_ICON_URI_MAX];
    char message[NOTIFY_MAX_MESSAGE_LEN];
} __attribute__((packed)) SceNotificationRequest;

/**
 * Initialize notification subsystem & resolve native symbols.
 * Returns true if initialization succeeded.
 */
bool notify_init(void);

/**
 * Display a native popup notification on PS4 screen.
 *
 * @param message The text message to display (UTF-8, <= 120-256 characters)
 * @param icon_uri Optional custom icon URI or NULL for default
 * @return 0 on success, negative error code on failure
 */
int notify_show(const char *message, const char *icon_uri);

/**
 * Cleanup notification resources.
 */
void notify_cleanup(void);

#ifdef __cplusplus
}
#endif

#endif /* SUGENGRAWUH_NOTIFY_H */
