#include "http_client.h"
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <sys/types.h>
#include <sys/socket.h>
#include <netinet/in.h>
#include <arpa/inet.h>
#include <netdb.h>
#include <fcntl.h>
#include <errno.h>

#define HTTP_BUFFER_SIZE 8192

bool http_client_init(void) {
    return true;
}

static int parse_url(const char *url, char *host, int *port, char *path) {
    if (strncmp(url, "http://", 7) != 0) {
        return -1;
    }
    const char *start = url + 7;
    const char *slash = strchr(start, '/');
    const char *colon = strchr(start, ':');

    if (colon && (!slash || colon < slash)) {
        size_t host_len = colon - start;
        strncpy(host, start, host_len);
        host[host_len] = '\0';
        *port = atoi(colon + 1);
    } else if (slash) {
        size_t host_len = slash - start;
        strncpy(host, start, host_len);
        host[host_len] = '\0';
        *port = 80;
    } else {
        strcpy(host, start);
        *port = 80;
    }

    if (slash) {
        strcpy(path, slash);
    } else {
        strcpy(path, "/");
    }

    return 0;
}

static HttpResponse execute_request(const char *method, const char *url, const char *token, const char *json_body, int timeout_sec) {
    HttpResponse res;
    res.status_code = -1;
    res.body = NULL;
    res.body_len = 0;

    char host[256];
    int port = 80;
    char path[1024];

    if (parse_url(url, host, &port, path) != 0) {
        return res;
    }

    int sock = socket(AF_INET, SOCK_STREAM, 0);
    if (sock < 0) {
        return res;
    }

    struct timeval tv;
    tv.tv_sec = timeout_sec > 0 ? timeout_sec : 5;
    tv.tv_usec = 0;
    setsockopt(sock, SOL_SOCKET, SO_RCVTIMEO, (const char *)&tv, sizeof(tv));
    setsockopt(sock, SOL_SOCKET, SO_SNDTIMEO, (const char *)&tv, sizeof(tv));

    struct sockaddr_in saddr;
    memset(&saddr, 0, sizeof(saddr));
    saddr.sin_family = AF_INET;
    saddr.sin_port = htons(port);
    inet_pton(AF_INET, host, &saddr.sin_addr);

    if (connect(sock, (struct sockaddr *)&saddr, sizeof(saddr)) < 0) {
        close(sock);
        return res;
    }

    char request[2048];
    int content_length = (json_body != NULL) ? (int)strlen(json_body) : 0;

    int req_len = snprintf(request, sizeof(request),
        "%s %s HTTP/1.1\r\n"
        "Host: %s:%d\r\n"
        "User-Agent: SugengRawuh-GoldHEN-Plugin/1.0\r\n"
        "Accept: application/json\r\n"
        "Authorization: Bearer %s\r\n"
        "X-Device-Token: %s\r\n"
        "Connection: close\r\n",
        method, path, host, port, token ? token : "", token ? token : "");

    if (content_length > 0) {
        req_len += snprintf(request + req_len, sizeof(request) - req_len,
            "Content-Type: application/json\r\n"
            "Content-Length: %d\r\n\r\n%s",
            content_length, json_body);
    } else {
        req_len += snprintf(request + req_len, sizeof(request) - req_len, "\r\n");
    }

    if (send(sock, request, req_len, 0) < 0) {
        close(sock);
        return res;
    }

    char *resp_buf = (char *)malloc(HTTP_BUFFER_SIZE);
    if (!resp_buf) {
        close(sock);
        return res;
    }
    memset(resp_buf, 0, HTTP_BUFFER_SIZE);

    int total_bytes = 0;
    int bytes_read = 0;
    while ((bytes_read = recv(sock, resp_buf + total_bytes, HTTP_BUFFER_SIZE - total_bytes - 1, 0)) > 0) {
        total_bytes += bytes_read;
        if (total_bytes >= HTTP_BUFFER_SIZE - 1) {
            break;
        }
    }
    close(sock);

    if (total_bytes <= 0) {
        free(resp_buf);
        return res;
    }
    resp_buf[total_bytes] = '\0';

    // Parse status code (e.g. HTTP/1.1 200 OK)
    char *status_line = strstr(resp_buf, "HTTP/1.");
    if (status_line) {
        res.status_code = atoi(status_line + 9);
    }

    // Locate start of body
    char *body_start = strstr(resp_buf, "\r\n\r\n");
    if (body_start) {
        body_start += 4;
        size_t b_len = total_bytes - (body_start - resp_buf);
        res.body = (char *)malloc(b_len + 1);
        if (res.body) {
            memcpy(res.body, body_start, b_len);
            res.body[b_len] = '\0';
            res.body_len = b_len;
        }
    }

    free(resp_buf);
    return res;
}

HttpResponse http_get(const char *url, const char *token, int timeout_sec) {
    return execute_request("GET", url, token, NULL, timeout_sec);
}

HttpResponse http_post(const char *url, const char *token, const char *json_body, int timeout_sec) {
    return execute_request("POST", url, token, json_body, timeout_sec);
}

void http_response_free(HttpResponse *res) {
    if (res && res->body) {
        free(res->body);
        res->body = NULL;
        res->body_len = 0;
    }
}

void http_client_cleanup(void) {
}
