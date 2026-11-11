TARGET   := sugengrawuh_notify
SRCS     := $(wildcard src/*.c)
OBJS     := $(SRCS:.c=.o)

ifdef OO_PS4_TOOLCHAIN
# Building with OpenOrbis Toolchain for PlayStation 4
CC       := clang
CFLAGS   := -target x86_64-pc-freebsd12-elf -fPIC -funwind-tables -I$(OO_PS4_TOOLCHAIN)/include -Isrc -O2 -Wall -D__ORBIS__
LDFLAGS  := -target x86_64-pc-freebsd12-elf -shared -L$(OO_PS4_TOOLCHAIN)/lib -lSceSysmodule -lSceNotificationRequest -lkernel

all: $(TARGET).prx

$(TARGET).prx: $(OBJS)
	$(CC) $(LDFLAGS) -o $@ $^
	@echo "Build complete: $(TARGET).prx"

%.o: %.c
	$(CC) $(CFLAGS) -c $< -o $@

else
# Host development runner (macOS / Linux)
CC       := clang
CFLAGS   := -Wall -Wextra -O2 -Isrc -pthread
LDFLAGS  := -pthread

all: $(TARGET)_host

$(TARGET)_host: $(OBJS)
	$(CC) $(LDFLAGS) -o $@ $^
	@echo "Host test build complete: $(TARGET)_host"

%.o: %.c
	$(CC) $(CFLAGS) -c $< -o $@

endif

clean:
	rm -f src/*.o $(TARGET).prx $(TARGET)_host

.PHONY: all clean
