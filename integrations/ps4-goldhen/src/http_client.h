#ifndef SUGENGRAWUH_HTTP_CLIENT_H
#define SUGENGRAWUH_HTTP_CLIENT_H

#include <stdint.h>
#include <stdbool.h>
#include <stddef.h>

#ifdef __cplusplus
extern "C" {
#endif

typedef struct HttpResponse {
    int status_code;
    char *body;
    size_t body_len;
} HttpResponse;

/**
 * Initialize HTTP network stack (sockets & Orbis net/http).
 */
bool http_client_init(void);

/**
 * Perform an HTTP GET request to the Laravel billing server.
 *
 * @param url Full URL string (e.g. "http://192.168.1.100:8000/api/console-agent/v1/events")
 * @param token Device Bearer token for authentication
 * @param timeout_sec Timeout in seconds
 * @return HttpResponse struct. Call http_response_free() when done.
 */
HttpResponse http_get(const char *url, const char *token, int timeout_sec);

/**
 * Perform an HTTP POST request with a JSON body.
 *
 * @param url Full URL string
 * @param token Device Bearer token for authentication
 * @param json_body JSON payload string
 * @param timeout_sec Timeout in seconds
 * @return HttpResponse struct. Call http_response_free() when done.
 */
HttpResponse http_post(const char *url, const char *token, const char *json_body, int timeout_sec);

/**
 * Free memory associated with an HttpResponse body.
 */
void http_response_free(HttpResponse *res);

/**
 * Cleanup HTTP subsystem.
 */
void http_client_cleanup(void);

#ifdef __cplusplus
}
#endif

#endif /* SUGENGRAWUH_HTTP_CLIENT_H */
