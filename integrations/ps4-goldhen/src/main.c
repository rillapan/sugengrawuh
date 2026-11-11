#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <stdbool.h>
#include <unistd.h>
#include <pthread.h>
#include "notify.h"
#include "http_client.h"

#define PLUGIN_VERSION "1.0.0"
#define DEFAULT_CONFIG_PATH "/data/GoldHEN/plugins/sugengrawuh/config.ini"

typedef struct PluginConfig {
    char server_url[256];
    char device_token[128];
    int poll_interval;
    int heartbeat_interval;
} PluginConfig;

static PluginConfig g_config;
static pthread_t g_worker_thread;
static bool g_running = false;

static void load_config(const char *path) {
    // Sensible defaults
    strncpy(g_config.server_url, "http://192.168.1.100:8000", sizeof(g_config.server_url) - 1);
    g_config.device_token[0] = '\0';
    g_config.poll_interval = 3;
    g_config.heartbeat_interval = 30;

    FILE *f = fopen(path, "r");
    if (!f) {
        // Fallback local file check
        f = fopen("config.ini", "r");
    }
    if (!f) return;

    char line[256];
    while (fgets(line, sizeof(line), f)) {
        char *eq = strchr(line, '=');
        if (!eq) continue;
        *eq = '\0';
        char *key = line;
        char *val = eq + 1;

        // Trim whitespace & newlines
        while (*key == ' ' || *key == '\t') key++;
        char *end = key + strlen(key) - 1;
        while (end > key && (*end == ' ' || *end == '\t' || *end == '\r' || *end == '\n')) *end-- = '\0';

        while (*val == ' ' || *val == '\t') val++;
        end = val + strlen(val) - 1;
        while (end > val && (*end == ' ' || *end == '\t' || *end == '\r' || *end == '\n')) *end-- = '\0';

        if (strcmp(key, "server_url") == 0) {
            strncpy(g_config.server_url, val, sizeof(g_config.server_url) - 1);
        } else if (strcmp(key, "device_token") == 0) {
            strncpy(g_config.device_token, val, sizeof(g_config.device_token) - 1);
        } else if (strcmp(key, "poll_interval") == 0) {
            g_config.poll_interval = atoi(val);
            if (g_config.poll_interval < 1) g_config.poll_interval = 3;
        } else if (strcmp(key, "heartbeat_interval") == 0) {
            g_config.heartbeat_interval = atoi(val);
            if (g_config.heartbeat_interval < 5) g_config.heartbeat_interval = 30;
        }
    }
    fclose(f);
}

static void send_heartbeat(void) {
    char url[512];
    snprintf(url, sizeof(url), "%s/api/console-agent/v1/heartbeat", g_config.server_url);

    char payload[256];
    snprintf(payload, sizeof(payload),
        "{\"homebrew_type\":\"GOLDHEN\",\"plugin_version\":\"%s\",\"firmware\":\"9.00\"}",
        PLUGIN_VERSION);

    HttpResponse res = http_post(url, g_config.device_token, payload, 3);
    http_response_free(&res);
}

static void send_ack(int event_id, const char *status, const char *error_msg) {
    char url[512];
    snprintf(url, sizeof(url), "%s/api/console-agent/v1/events/%d/ack", g_config.server_url, event_id);

    char payload[256];
    if (error_msg) {
        snprintf(payload, sizeof(payload), "{\"status\":\"%s\",\"error_message\":\"%s\"}", status, error_msg);
    } else {
        snprintf(payload, sizeof(payload), "{\"status\":\"%s\"}", status);
    }

    HttpResponse res = http_post(url, g_config.device_token, payload, 5);
    http_response_free(&res);
}

static void *worker_loop(void *arg) {
    (void)arg;
    int heartbeat_timer = 0;

    // Send initial startup notification & initial heartbeat
    notify_show("Sugeng Rawuh Console Agent connected.", NULL);
    send_heartbeat();

    while (g_running) {
        sleep(g_config.poll_interval);

        heartbeat_timer += g_config.poll_interval;
        if (heartbeat_timer >= g_config.heartbeat_interval) {
            send_heartbeat();
            heartbeat_timer = 0;
        }

        char url[512];
        snprintf(url, sizeof(url), "%s/api/console-agent/v1/events", g_config.server_url);

        HttpResponse res = http_get(url, g_config.device_token, 4);
        if (res.status_code == 200 && res.body && res.body_len > 0) {
            // Check if there is an active event
            if (strstr(res.body, "\"has_events\":true") != NULL) {
                // Extract event_id
                int event_id = 0;
                char *id_ptr = strstr(res.body, "\"event_id\":");
                if (!id_ptr) id_ptr = strstr(res.body, "\"id\":");
                if (id_ptr) {
                    char *colon = strchr(id_ptr, ':');
                    if (colon) event_id = atoi(colon + 1);
                }

                // Extract message body
                char message[256] = {0};
                char *msg_ptr = strstr(res.body, "\"message\":\"");
                if (msg_ptr) {
                    msg_ptr += 11;
                    char *quote = strchr(msg_ptr, '"');
                    if (quote) {
                        size_t len = quote - msg_ptr;
                        if (len >= sizeof(message)) len = sizeof(message) - 1;
                        strncpy(message, msg_ptr, len);
                        message[len] = '\0';
                    }
                }

                if (event_id > 0 && strlen(message) > 0) {
                    // Display popup natively on PS4
                    int notify_ret = notify_show(message, NULL);
                    if (notify_ret == 0) {
                        send_ack(event_id, "NOTIFICATION_API_CALLED", NULL);
                    } else {
                        char err[64];
                        snprintf(err, sizeof(err), "sceNotificationRequest failed: %d", notify_ret);
                        send_ack(event_id, "FAILED", err);
                    }
                }
            }
        }
        http_response_free(&res);
    }

    return NULL;
}

// GoldHEN Plugin Lifecycle Entry Points
int Plugin_Init(void) {
    notify_init();
    http_client_init();

    load_config(DEFAULT_CONFIG_PATH);

    g_running = true;
    pthread_create(&g_worker_thread, NULL, worker_loop, NULL);

    return 0;
}

int Plugin_Exit(void) {
    g_running = false;
    pthread_join(g_worker_thread, NULL);

    notify_cleanup();
    http_client_cleanup();

    return 0;
}

#ifndef __ORBIS__
// Standalone runner for testing & verification on development workstations
int main(int argc, char **argv) {
    (void)argc;
    (void)argv;
    printf("[SugengRawuh] Running standalone console agent test runner\n");
    Plugin_Init();
    sleep(1);
    Plugin_Exit();
    printf("[SugengRawuh] Agent runner test complete\n");
    return 0;
}
#endif
