CXX = g++
CXXFLAGS = -std=c++11 -Wall -Wno-unused-function -Wno-unused-but-set-variable -Wno-unused-variable -Iinclude
LDFLAGS = -lmysqlclient -lcurl -lpthread -lcrypto
CHALLENGE_LDFLAGS = -lmysqlclient -lpthread -lcrypto -lssl
PREFIX ?= /pteroprotect
PANEL_DIR ?= /var/www/pterodactyl
SYSTEMD_DIR ?= /etc/systemd/system
NGINX_DIR ?= /etc/nginx

SRCDIR = src
INCDIR = include
OBJDIR = obj
BINDIR = .

GUARD_SOURCES = \
	$(SRCDIR)/config.cpp \
	$(SRCDIR)/db_guard.cpp \
	$(SRCDIR)/disk_protect.cpp \
	$(SRCDIR)/logger.cpp \
	$(SRCDIR)/main.cpp \
	$(SRCDIR)/rate_protect.cpp \
	$(SRCDIR)/resource_monitor.cpp \
	$(SRCDIR)/telegram.cpp \
	$(SRCDIR)/tracker_db.cpp \
	$(SRCDIR)/tracking.cpp
GUARD_OBJECTS = $(GUARD_SOURCES:$(SRCDIR)/%.cpp=$(OBJDIR)/%.o)
TARGET = $(BINDIR)/dann_guard
CHALLENGE_TARGET = $(BINDIR)/challenge_guard
CHALLENGE_SOURCE = $(SRCDIR)/challenge_guard.cpp
CHALLENGE_OBJECT = $(OBJDIR)/challenge_guard.o

all: $(TARGET) $(CHALLENGE_TARGET)

$(TARGET): $(GUARD_OBJECTS)
	@mkdir -p $(BINDIR)
	$(CXX) $(GUARD_OBJECTS) -o $@ $(LDFLAGS)

$(CHALLENGE_TARGET): $(CHALLENGE_OBJECT)
	@mkdir -p $(BINDIR)
	$(CXX) $(CHALLENGE_OBJECT) -o $@ $(CHALLENGE_LDFLAGS)

$(OBJDIR)/%.o: $(SRCDIR)/%.cpp $(wildcard $(INCDIR)/*.h)
	@mkdir -p $(OBJDIR)
	$(CXX) $(CXXFLAGS) -c $< -o $@

clean:
	rm -rf $(OBJDIR) $(TARGET) $(CHALLENGE_TARGET)

install:
	INSTALL_DIR="$(PREFIX)" PANEL_DIR="$(PANEL_DIR)" SYSTEMD_DIR="$(SYSTEMD_DIR)" NGINX_DIR="$(NGINX_DIR)" bash ./setup.sh

uninstall:
	rm -f /usr/local/bin/dann_guard
	rm -f $(PREFIX)/dann_guard
	rm -f $(PREFIX)/config.json
	rm -f $(SYSTEMD_DIR)/pteroprotect.service

.PHONY: all clean install uninstall
