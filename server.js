  const path = require("path");
  require("dotenv").config({ path: path.join(__dirname, ".env") });

  const express = require("express");
  const http = require("http");
  const cors = require("cors");
  const { Server } = require("socket.io");
  function resolveLaravelApiBase() {
    const explicit = (process.env.LARAVEL_API_URL || "").trim();
    if (explicit) return explicit.replace(/\/+$/, "");

    const appUrl = (process.env.APP_URL || "").trim();
    if (!appUrl) return "http://127.0.0.1";

    try {
      const u = new URL(appUrl);
      const local = u.hostname === "localhost" || u.hostname === "127.0.0.1" || u.hostname === "::1";
      if (local) {
        return `${u.protocol}//${u.host}`.replace(/\/+$/, "");
      }
      const port = u.port ? `:${u.port}` : "";
      return `http://127.0.0.1${port}`;
    } catch {
      return "http://127.0.0.1";
    }
  }

  const SOCKET_PORT = Number(process.env.SOCKET_PORT || 6001);
  const LARAVEL_API_URL = resolveLaravelApiBase();
  const SOCKET_INTERNAL_SECRET = process.env.SOCKET_INTERNAL_SECRET || "";
  const RIDE_VISIBILITY_MS = Math.max(
    60000,
    Number(process.env.RIDE_VISIBILITY_SECONDS || 60) * 1000
  );

  function getForcedRidesFromBroadcast(data) {
    if (!data) return [];

    if (Array.isArray(data.forced_rides) && data.forced_rides.length > 0) {
      return data.forced_rides;
    }

    if (data.ride_details) {
      return [data.ride_details];
    }

    if (data.ride && data.ride.ride_details) {
      return [data.ride.ride_details];
    }

    if (data.ride && typeof data.ride === "object" && (data.ride.id || data.ride.ride_id)) {
      return [data.ride];
    }

    return [];
  }

  function buildFreshRideShowPayload(rides, options = {}) {
    const list = Array.isArray(rides) ? rides : [];
    const rideId = list[0]?.id ?? list[0]?.ride_id ?? options.ride_id ?? null;
    const isHide = options.hidden === true || (list.length === 0 && options.hideEmpty);

    return {
      success: true,
      data: sanitizeRidesForApp(list),
      count: list.length,
      hidden: isHide,
      timestamp: new Date().toISOString(),
      ...(isHide ? { reason: options.reason || "visibility_timeout" } : {}),
      ...(list.length > 0 && !isHide && rideId
        ? { show_token: `${rideId}-${Date.now()}` }
        : {}),
      visibility_seconds: RIDE_VISIBILITY_MS / 1000,
    };
  }

  async function emitRideVisibilityReset(userId, rideId, reason = "fare_updated") {
    if (!rideId) return;

    const payload = {
      ride_id: rideId,
      visibility_seconds: RIDE_VISIBILITY_MS / 1000,
      reason,
      fare_updated: reason === "fare_updated",
      reshow: true,
      timestamp: new Date().toISOString(),
    };

    io.to(`user:${userId}`).emit("driver:ride-visibility-reset", payload);

    const sockets = await io.fetchSockets();
    for (const sock of sockets) {
      const meta = socketMeta.get(sock.id);
      if (!meta || Number(meta.userId) !== Number(userId)) continue;
      sock.emit("driver:ride-visibility-reset", payload);
    }

    console.log(
      "[socket] 🔁 driver:ride-visibility-reset user_id=%s ride_id=%s reason=%s",
      userId,
      rideId,
      reason
    );
  }

  function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
  }

  function buildCallbackRidePayload(rides, options = {}) {
    const list = Array.isArray(rides) ? rides : [];
    const rideId = list[0]?.id ?? list[0]?.ride_id ?? options.ride_id ?? null;
    const asNewRide = !!options.as_new_ride;
    const fareUpdated = !!options.fare_updated && !asNewRide;

    const payload = {
      success: true,
      data: sanitizeRidesForApp(list),
      count: list.length,
      timestamp: new Date().toISOString(),
      ...(rideId ? { show_token: `${rideId}-${Date.now()}` } : {}),
    };

    if (asNewRide) {
      payload.ride_id = rideId;
      return payload;
    }

    if (fareUpdated) {
      payload.fare_updated = true;
      payload.reshow = true;
      payload.visibility_reset = true;
      payload.ride_id = rideId;
      payload.reason = "fare_updated";
    }

    return payload;
  }

  function buildNewRideSocketPayload(ride) {
    const rideId = ride?.id ?? ride?.ride_id ?? null;
    const clean = sanitizeRidesForApp([ride])[0] || ride;

    return {
      ride: clean,
      ride_id: rideId,
      id: rideId,
      ride_details: clean,
      start: clean.start ?? ride.start,
      destination: clean.destination ?? ride.destination,
      estimated_fare: clean.final_fare ?? clean.estimated_fare ?? ride.final_fare ?? ride.estimated_fare,
      final_fare: clean.final_fare ?? clean.estimated_fare ?? ride.final_fare ?? ride.estimated_fare,
      vehicle_category_id: clean.vehicle_category_id ?? ride.vehicle_category_id,
      status: clean.status ?? ride.status,
      message: "New ride available nearby",
      success: true,
      count: 1,
      timestamp: new Date().toISOString(),
      show_token: rideId ? `${rideId}-${Date.now()}` : undefined,
    };
  }

  async function emitToDriver(userId, eventName, payload) {
    const room = `user:${userId}`;
    io.to(room).emit(eventName, payload);

    const sockets = await io.fetchSockets();
    for (const sock of sockets) {
      const meta = socketMeta.get(sock.id);
      if (!meta || Number(meta.userId) !== Number(userId)) continue;
      sock.emit(eventName, payload);
    }
  }

  async function emitNearbyRidesListShow(userId, rides, logSource = "show") {
    const list = Array.isArray(rides) ? rides : [];
    if (list.length === 0) return null;

    const payload = buildFreshRideShowPayload(list, { hidden: false });
    const rideId = list[0]?.id ?? list[0]?.ride_id ?? null;

    await emitToDriver(userId, "driver:nearby-rides:list", payload);
    // Some app builds listen to callback-style result event for UI updates
    await emitToDriver(userId, "driver:nearby-rides:result", payload);

    const sockets = await io.fetchSockets();
    let directCount = 0;
    for (const sock of sockets) {
      const meta = socketMeta.get(sock.id);
      if (!meta || Number(meta.userId) !== Number(userId)) continue;
      directCount++;
    }

    console.log(
      "[socket] 📤 driver:nearby-rides:list user_id=%s count=%d hidden=false ride_id=%s source=%s sockets=%d",
      userId,
      payload.count,
      rideId,
      logSource,
      directCount
    );

    return payload;
  }

  async function emitDriverRideEvents(userId, rides, options = {}) {
    const list = Array.isArray(rides) ? rides : [];
    const ride = list[0] || null;
    const rideId = ride?.id ?? ride?.ride_id ?? options.ride_id ?? null;
    const fareUpdated = !!options.fare_updated;
    const room = `user:${userId}`;

    const listPayload = buildFreshRideShowPayload(list, {
      hidden: false,
      fare_updated: fareUpdated,
      ride_id: rideId,
    });

    io.to(room).emit("driver:nearby-rides:list", listPayload);

    if (ride) {
      const newRidePayload = buildNewRideSocketPayload(ride);
      newRidePayload.message = fareUpdated
        ? "Updated fare ride available nearby"
        : "New ride available nearby";
      io.to(room).emit("driver:new-ride-available", newRidePayload);
    }

    const sockets = await io.fetchSockets();
    let directCount = 0;
    for (const sock of sockets) {
      const meta = socketMeta.get(sock.id);
      if (!meta || Number(meta.userId) !== Number(userId)) continue;
      sock.emit("driver:nearby-rides:list", listPayload);
      if (ride) {
        const newRidePayload = buildNewRideSocketPayload(ride);
        newRidePayload.message = fareUpdated
          ? "Updated fare ride available nearby"
          : "New ride available nearby";
        sock.emit("driver:new-ride-available", newRidePayload);
      }
      directCount++;
    }

    console.log(
      "[socket] 📤 driver:nearby-rides:list user_id=%s count=%d hidden=false ride_id=%s fare=%s sockets=%d",
      userId,
      listPayload.count,
      rideId,
      fareUpdated,
      directCount
    );

    return listPayload;
  }

  async function emitNearbyRidesListEvent(userId, rides, options = {}) {
    if (options.hidden === true || (options.hideEmpty && (!rides || rides.length === 0))) {
      const rideId = options.ride_id ?? null;
      const payload = {
        success: true,
        count: 0,
        hidden: true,
        reason: options.reason || "visibility_timeout",
        ride_id: rideId,
        timestamp: new Date().toISOString(),
      };
      const room = `user:${userId}`;
      io.to(room).emit("driver:nearby-rides:list", payload);

      const sockets = await io.fetchSockets();
      let directCount = 0;
      for (const sock of sockets) {
        const meta = socketMeta.get(sock.id);
        if (!meta || Number(meta.userId) !== Number(userId)) continue;
        sock.emit("driver:nearby-rides:list", payload);
        directCount++;
      }

      console.log(
        "[socket] 📤 driver:nearby-rides:list user_id=%s count=0 hidden=true ride_id=%s sockets=%d",
        userId,
        rideId,
        directCount
      );
      return payload;
    }

    return emitDriverRideEvents(userId, rides, {
      fare_updated: !!options.fare_updated,
      reshow: !!options.reshow,
      visibility_reset: !!options.visibility_reset,
      ride_id: options.ride_id,
    });
  }

  async function emitShowRidesToDriver(userId, rides, source = "visible", fareUpdated = false) {
    const state = getDriverRideState(userId);
    let list = Array.isArray(rides) ? rides : [];

    state.hidden = false;
    state.showProtectedUntil = Date.now() + 15000;
    state.lastRides = list;

    if (state.hideTimer) {
      clearTimeout(state.hideTimer);
      state.hideTimer = null;
    }

    if (list.length === 0) {
      return;
    }

    rememberDriverShownRide(userId, list);
    await emitNearbyRidesListShow(userId, list, fareUpdated ? "fare_updated" : source);
    lockDriverRideShow(userId, list, fareUpdated ? "fare_updated" : source);
  }

  function isShowProtected(userId) {
    const state = getDriverRideState(userId);
    return !!(state.showProtectedUntil && Date.now() < state.showProtectedUntil);
  }

  function sanitizeRidesForApp(rides) {
    if (!Array.isArray(rides)) {
      return [];
    }

    return rides.map((ride) => {
      const copy = { ...(ride || {}) };
      delete copy.fare_updated;
      delete copy.hidden;
      delete copy.reshow;
      delete copy.visibility_reset;
      return copy;
    });
  }

  function buildNearbyRidesListPayload(rides, options = {}) {
    if (options.asFreshRide) {
      return buildFreshRideShowPayload(rides);
    }

    const list = Array.isArray(rides) ? rides : [];
    const rideId = options.ride_id ?? list[0]?.id ?? list[0]?.ride_id ?? null;
    const isFareUpdated = !!options.fare_updated;

    return {
      success: true,
      data: sanitizeRidesForApp(list),
      count: list.length,
      timestamp: new Date().toISOString(),
      hidden: options.hidden ?? (list.length > 0 ? false : true),
      fare_updated: isFareUpdated,
      reshow: !!options.reshow || isFareUpdated,
      visibility_reset: !!options.visibility_reset || isFareUpdated,
      ride_id: rideId,
      show_token: options.show_token ?? (rideId ? `${rideId}-${Date.now()}` : undefined),
      visibility_seconds: options.visibility_seconds ?? RIDE_VISIBILITY_MS / 1000,
      reason:
        options.reason
        ?? (isFareUpdated ? "fare_updated" : list.length > 0 ? "visible" : "empty"),
    };
  }

  const RIDE_LIST_REFRESH_EVENTS = new Set([
    "driver:nearby-rides:list",
    "driver:new-ride-available",
    "driver:rides-list-updated",
  ]);

  function mergeForcedRides(rides, forcedRides) {
    if (!Array.isArray(forcedRides) || forcedRides.length === 0) {
      return Array.isArray(rides) ? rides : [];
    }

    const list = Array.isArray(rides) ? [...rides] : [];

    forcedRides.forEach((forced) => {
      const forcedId = forced.id ?? forced.ride_id;
      if (forcedId == null) return;

      const idx = list.findIndex((ride) => {
        const rideId = ride.id ?? ride.ride_id;
        return String(rideId) === String(forcedId);
      });

      const merged = {
        ...(idx >= 0 ? list[idx] : {}),
        ...forced,
        id: forced.id ?? forced.ride_id ?? forcedId,
      };

      if (idx >= 0) {
        list[idx] = merged;
      } else {
        list.unshift(merged);
      }
    });

    return list;
  }

  function getEligibleDriverIdsFromBroadcast(data) {
    if (!data) return [];

    if (Array.isArray(data.eligible_driver_ids) && data.eligible_driver_ids.length > 0) {
      return data.eligible_driver_ids.map((id) => Number(id)).filter((id) => !Number.isNaN(id));
    }

    if (data.ride && Array.isArray(data.ride.eligible_driver_ids) && data.ride.eligible_driver_ids.length > 0) {
      return data.ride.eligible_driver_ids.map((id) => Number(id)).filter((id) => !Number.isNaN(id));
    }

    return [];
  }

  async function deliverRidesListToEligibleDrivers(data, sourceEvent = "driver:nearby-rides:list") {
    const forcedRides = getForcedRidesFromBroadcast(data);
    const fareUpdated = !!(data && (data.fare_updated || data.reason === "fare_updated"));
    const rideId = getRideIdFromBroadcast(data, forcedRides);

    let eligibleIds = getEligibleDriverIdsFromBroadcast(data);

    if (fareUpdated && rideId) {
      const previouslyShown = getDriversPreviouslyShownRide(rideId);
      eligibleIds = [...new Set([...eligibleIds, ...previouslyShown])];
    }

    if (eligibleIds.length === 0) {
      console.log("[socket] ⚠ fare update: no eligible drivers ride_id=%s", rideId);
      return 0;
    }

    let deliveredCount = 0;

    for (const driverId of eligibleIds) {
      const payloadRides = forcedRides.map((ride) => ({
        ...ride,
        id: ride.id ?? ride.ride_id,
        final_fare: ride.final_fare ?? ride.estimated_fare,
        estimated_fare: ride.estimated_fare ?? ride.final_fare,
      }));

      await forceShowRidesForDriver(driverId, payloadRides, sourceEvent, {
        fareUpdated,
        visibilityReset: !!(data && data.visibility_reset),
        visibilitySeconds: data?.visibility_seconds,
        reason: data?.reason || (fareUpdated ? "fare_updated" : "visible"),
      });

      deliveredCount++;
      console.log(
        "[socket] ✓ driver:nearby-rides:list delivered to user_id=%s rides=%d online=%d",
        driverId,
        payloadRides.length,
        getUserOnlineCount(driverId)
      );
    }

    return deliveredCount;
  }

  function getTargetDriverIdsFromBroadcast(data, forcedRides = []) {
    const rideId = getRideIdFromBroadcast(data, forcedRides);
    const targetIds = new Set(getEligibleDriverIdsFromBroadcast(data));

    if (rideId) {
      getDriversPreviouslyShownRide(rideId).forEach((id) => targetIds.add(Number(id)));
    }

    if (Array.isArray(data?.previously_notified_driver_ids)) {
      data.previously_notified_driver_ids.forEach((id) => targetIds.add(Number(id)));
    }

    return { rideId, targetIds };
  }

  async function refreshConnectedDriverSockets(data, event) {
    const forcedRides = getForcedRidesFromBroadcast(data);
    const fareUpdated = !!(data && (data.fare_updated || data.reason === "fare_updated"));
    const { rideId, targetIds } = getTargetDriverIdsFromBroadcast(data, forcedRides);

    if (forcedRides.length > 0) {
      const sockets = await io.fetchSockets();
      let driverRefreshCount = 0;

      for (const sock of sockets) {
        if (!sock.data.isDriver || !sock.data.refreshNearbyRides) {
          continue;
        }

        const driverId = Number(sock.data.userId);
        if (targetIds.size > 0 && !targetIds.has(driverId)) {
          continue;
        }

        await sock.data.refreshNearbyRides({
          forceRides: forcedRides,
          fareUpdated,
          source: fareUpdated ? "fare_updated" : event,
        });
        driverRefreshCount++;
      }

      console.log(
        "[socket] ✓ driver:nearby-rides:list refreshed %d online drivers (ride_id=%s fare=%s)",
        driverRefreshCount,
        rideId,
        fareUpdated
      );
      return driverRefreshCount;
    }

    let eligibleIds = [...targetIds];

    if (eligibleIds.length > 0) {
      const delivered = await deliverRidesListToEligibleDrivers(data, event);
      console.log("[socket] ✓ driver:nearby-rides:list sent to %d eligible drivers", delivered);
      return delivered;
    }

    const sockets = await io.fetchSockets();
    let driverRefreshCount = 0;
    for (const sock of sockets) {
      if (!sock.data.isDriver || !sock.data.refreshNearbyRides) {
        continue;
      }

      await sock.data.refreshNearbyRides({
        forceRides: forcedRides,
        fareUpdated,
        source: event,
      });
      driverRefreshCount++;
    }

    return driverRefreshCount;
  }
  const SOCKET_CORS_ORIGIN = process.env.SOCKET_CORS_ORIGIN || "*";
  const fetchFn = (...args) => {
    if (typeof fetch !== "undefined") {
      return fetch(...args);
    }
    return import("node-fetch").then(({ default: fetchPolyfill }) => fetchPolyfill(...args));
  };

  const app = express();
  app.use(express.json({ limit: "10mb" }));
  app.use(cors({ origin: SOCKET_CORS_ORIGIN === "*" ? true : SOCKET_CORS_ORIGIN.split(",") }));

  // Register plain HTTP routes before Socket.IO attaches (avoids "Cannot GET /" on older deploy semantics).
  app.get("/", (_, res) => {
    const portHint = SOCKET_PORT === 6001 ? "" : ` (configured port ${SOCKET_PORT})`;
    res.json({
      ok: true,
      service: "socket-server",
      health: "/health",
      socket_io_path: "/socket.io/",
      hint: `Browser address bar alone does not open a realtime socket. Use the Socket.IO client; URL must be origin only${portHint}.`,
      client_example:
        'io("http://YOUR_HOST:YOUR_PORT", { auth: { token: "<jwt>" } }) — do not append socket URL onto your REST API base path.',
    });
  });

  app.get("/health", (_, res) => {
    res.json({ ok: true, service: "socket-server" });
  });

  const server = http.createServer(app);
  const io = new Server(server, {
    cors: {
      origin: SOCKET_CORS_ORIGIN === "*" ? true : SOCKET_CORS_ORIGIN.split(","),
      methods: ["GET", "POST"],
    },
    pingTimeout: 60000,
    pingInterval: 25000,
  });

  const userSockets = new Map(); // userId -> Set(socket.id)
  const socketMeta = new Map(); // socket.id -> { userId, token, roomIds[] }
  const driverRideState = new Map(); // userId -> { hidden, lastRides, hideTimer }
  const rideShownToDrivers = new Map(); // rideId -> Set(userId) — drivers who saw this ride on socket

  function rememberDriverShownRide(userId, rides) {
    const list = Array.isArray(rides) ? rides : [];
    for (const ride of list) {
      const rideId = Number(ride?.id ?? ride?.ride_id ?? 0);
      if (!rideId) continue;
      if (!rideShownToDrivers.has(rideId)) {
        rideShownToDrivers.set(rideId, new Set());
      }
      rideShownToDrivers.get(rideId).add(Number(userId));
    }
  }

  function getDriversPreviouslyShownRide(rideId) {
    const set = rideShownToDrivers.get(Number(rideId));
    return set ? [...set] : [];
  }

  function getRideIdFromBroadcast(data, forcedRides = []) {
    if (!data) return null;
    if (data.ride_id) return Number(data.ride_id);
    if (data.ride?.ride_id) return Number(data.ride.ride_id);
    if (data.ride?.id) return Number(data.ride.id);
    const first = forcedRides[0];
    if (first) return Number(first.id ?? first.ride_id ?? 0) || null;
    return null;
  }

  function getDriverRideState(userId) {
    const id = Number(userId);
    if (!driverRideState.has(id)) {
      driverRideState.set(id, {
        hidden: false,
        lastRides: [],
        hideTimer: null,
        showProtectedUntil: 0,
        keepAliveInterval: null,
      });
    }
    return driverRideState.get(id);
  }

  function clearDriverRideState(userId) {
    const state = driverRideState.get(Number(userId));
    if (state?.hideTimer) {
      clearTimeout(state.hideTimer);
    }
    if (state?.keepAliveInterval) {
      clearInterval(state.keepAliveInterval);
    }
    driverRideState.delete(Number(userId));
  }

  function lockDriverRideShow(userId, rides, source = "show") {
    const state = getDriverRideState(userId);
    const list = Array.isArray(rides) ? rides : [];

    state.hidden = false;
    state.lastRides = list;
    state.showProtectedUntil = Date.now() + RIDE_VISIBILITY_MS;
    rememberDriverShownRide(userId, list);
    scheduleDriverRideHide(userId, source);

    if (state.keepAliveInterval) {
      clearInterval(state.keepAliveInterval);
      state.keepAliveInterval = null;
    }

    if (list.length === 0) {
      return;
    }

    // Re-send the same list during the 1-minute window so API/push refresh cannot hide it.
    state.keepAliveInterval = setInterval(() => {
      if (!isShowProtected(userId) || !state.lastRides?.length) {
        clearInterval(state.keepAliveInterval);
        state.keepAliveInterval = null;
        return;
      }
      emitNearbyRidesListShow(userId, state.lastRides, "keep-alive-60s").catch(() => {});
    }, 8000);
  }

  async function hideDriverRidesNow(userId, force = false) {
    const state = getDriverRideState(userId);

    if (!force && isShowProtected(userId)) {
      console.log("[socket] ↷ Skip auto-hide user_id=%s (show protection)", userId);
      return;
    }

    state.hidden = true;
    state.lastRides = [];
    state.showProtectedUntil = 0;

    if (state.hideTimer) {
      clearTimeout(state.hideTimer);
      state.hideTimer = null;
    }
    if (state.keepAliveInterval) {
      clearInterval(state.keepAliveInterval);
      state.keepAliveInterval = null;
    }

    // Server-side only — do not emit hide to app (breaks fare re-show for same ride_id).
    console.log("[socket] ⏱ Ride hidden (server-side) user_id=%s", userId);
  }

  // Ride no longer available to drivers (canceled / accepted / finished).
  const RIDE_REMOVAL_ACTIONS = new Set(["ride_canceled", "bid_accepted"]);
  const RIDE_OPEN_STATUSES = new Set(["requested", "in_progress"]);

  function isRideRemovalBroadcast(data) {
    if (!data) return false;
    if (RIDE_REMOVAL_ACTIONS.has(data.action)) return true;
    const status = data.ride && data.ride.status;
    return !!status && !RIDE_OPEN_STATUSES.has(String(status));
  }

  function rideKey(ride) {
    const id = ride?.id ?? ride?.ride_id;
    return id == null ? null : String(id);
  }

  // Ride ids Laravel still returns for this driver; null when the API cannot be reached.
  async function fetchLiveRideKeysForDriver(userId) {
    const sockets = await io.fetchSockets();
    for (const sock of sockets) {
      const meta = socketMeta.get(sock.id);
      if (!meta || Number(meta.userId) !== Number(userId) || !meta.token) {
        continue;
      }

      try {
        const raw = await laravelFetch("/api/driver/near/by/ride", {
          method: "GET",
          headers: { Authorization: `Bearer ${meta.token}` },
        });
        const rides = Array.isArray(raw?.data) ? raw.data : [];
        return new Set(rides.map(rideKey).filter((key) => key !== null));
      } catch (error) {
        console.error(
          "[socket] ✗ fetchLiveRideKeysForDriver user_id=%s failed: %s",
          userId,
          error.message
        );
      }
    }

    return null;
  }

  // Drop closed rides from every driver's locked list so keep-alive stops
  // re-sending a ride the API no longer returns.
  async function purgeClosedRidesFromDrivers(data) {
    const closedRideId = getRideIdFromBroadcast(data);
    const reason = data?.action || "ride_closed";
    let purgedCount = 0;

    for (const [userId, state] of driverRideState) {
      const locked = Array.isArray(state.lastRides) ? state.lastRides : [];
      if (locked.length === 0) continue;

      const liveKeys = await fetchLiveRideKeysForDriver(userId);
      const kept = locked.filter((ride) => {
        const key = rideKey(ride);
        if (liveKeys) return key !== null && liveKeys.has(key);
        return closedRideId == null || key !== String(closedRideId);
      });

      if (kept.length === locked.length) continue;
      purgedCount++;

      if (kept.length === 0) {
        await hideDriverRidesNow(userId, true);
        const payload = {
          ...buildFreshRideShowPayload([], { hidden: true, reason }),
          ride_id: closedRideId,
        };
        await emitToDriver(userId, "driver:nearby-rides:list", payload);
        await emitToDriver(userId, "driver:nearby-rides:result", payload);
      } else {
        state.lastRides = kept;
        await emitNearbyRidesListShow(userId, kept, reason);
      }

      console.log(
        "[socket] 🧹 Removed closed ride from driver list user_id=%s ride_id=%s reason=%s remaining=%d",
        userId,
        closedRideId,
        reason,
        kept.length
      );
    }

    return purgedCount;
  }

  async function fetchNearbyRidesForDriver(userId, forceRides = []) {
    const sockets = await io.fetchSockets();
    for (const sock of sockets) {
      const meta = socketMeta.get(sock.id);
      if (!meta || Number(meta.userId) !== Number(userId) || !meta.token) {
        continue;
      }

      try {
        const nearbyRides = await laravelFetch("/api/driver/near/by/ride", {
          method: "GET",
          headers: {
            Authorization: `Bearer ${meta.token}`,
          },
        });
        return mergeForcedRides(nearbyRides?.data || [], forceRides);
      } catch (error) {
        console.error(
          "[socket] ✗ fetchNearbyRidesForDriver user_id=%s failed: %s — using forced rides only",
          userId,
          error.message
        );
        if (forceRides.length > 0) {
          return mergeForcedRides([], forceRides);
        }
      }
    }

    return mergeForcedRides([], forceRides);
  }

  function scheduleDriverRideHide(userId, source = "unknown") {
    const state = getDriverRideState(userId);
    if (state.hideTimer) {
      clearTimeout(state.hideTimer);
      state.hideTimer = null;
    }

    console.log(
      "[socket] ⏱ Scheduling ride hide in %ds for driver user_id=%s (source=%s)",
      RIDE_VISIBILITY_MS / 1000,
      userId,
      source
    );

    state.hideTimer = setTimeout(() => {
      state.hideTimer = null;
      hideDriverRidesNow(userId, true).catch(() => {});
    }, RIDE_VISIBILITY_MS);
  }

  async function forceShowRidesForDriver(userId, rides, source = "fare_updated", options = {}) {
    const forcedRides = Array.isArray(rides) ? rides : [];
    let toShow = forcedRides;

    if (!options.useForcedOnly) {
      const mergedRides = await fetchNearbyRidesForDriver(userId, forcedRides);
      toShow = mergedRides.length > 0 ? mergedRides : forcedRides;
    }

    if (toShow.length === 0) {
      console.log("[socket] ⚠ no rides to show for driver user_id=%s (source=%s)", userId, source);
      return;
    }

    const fareUpdated = options.fareUpdated === true || source.includes("fare");
    await emitShowRidesToDriver(userId, toShow, source, fareUpdated);
  }

  function authTokenFromSocket(socket) {
    const fromAuth = socket.handshake.auth && socket.handshake.auth.token;
    if (fromAuth) return fromAuth;
    const authHeader = socket.handshake.headers && socket.handshake.headers.authorization;
    if (!authHeader) return null;
    if (authHeader.startsWith("Bearer ")) return authHeader.slice(7);
    return authHeader;
  }

  function getUserOnlineCount(userId) {
    const set = userSockets.get(userId);
    return set ? set.size : 0;
  }

  function isUserOnline(userId) {
    return getUserOnlineCount(userId) > 0;
  }

  async function laravelFetch(path, options = {}) {
    const url = `${LARAVEL_API_URL}${path}`;
    let response;
    try {
      response = await fetchFn(url, {
        ...options,
        headers: {
          Accept: "application/json",
          ...(options.headers || {}),
        },
      });
    } catch (e) {
      const err = e instanceof Error ? e.message : String(e);
      throw new Error(
        `Cannot reach Laravel at ${url} (${err}). Set LARAVEL_API_URL in .env (e.g. http://127.0.0.1 or http://127.0.0.1:8081) if nginx uses a non-default port.`
      );
    }

    const text = await response.text();
    let body = null;
    try {
      body = text ? JSON.parse(text) : null;
    } catch {
      body = text ? { _nonJson: text.slice(0, 400) } : null;
    }

    if (!response.ok) {
      const message =
        (body && body.message) ||
        (body && body._nonJson) ||
        `Laravel API error ${response.status}`;
      throw new Error(typeof message === "string" ? message : JSON.stringify(message));
    }

    return body;
  }

  async function touchActivity(userId) {
    if (!SOCKET_INTERNAL_SECRET) {
      return;
    }

    try {
      await laravelFetch("/api/socket/internal/activity", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "x-socket-secret": SOCKET_INTERNAL_SECRET,
        },
        body: JSON.stringify({ user_id: userId }),
      });
    } catch (error) {
      console.error("Activity touch failed:", error.message);
    }
  }

  async function setPresence(userId, online, appForeground = null) {
    if (!SOCKET_INTERNAL_SECRET) {
      return;
    }

    try {
      const payload = {
        user_id: userId,
        is_online: !!online,
      };

      if (appForeground !== null) {
        payload.is_app_foreground = !!appForeground;
      }

      await laravelFetch("/api/socket/internal/presence", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "x-socket-secret": SOCKET_INTERNAL_SECRET,
        },
        body: JSON.stringify(payload),
      });
    } catch (error) {
      console.error("Presence update failed:", error.message);
    }
  }

  async function authenticateSocket(socket, token) {
    try {
      const payload = await laravelFetch("/api/socket/me", {
        method: "GET",
        headers: {
          Authorization: `Bearer ${token}`,
          Accept: "application/json",
        },
      });

      if (!payload || typeof payload !== "object") {
        throw new Error("Invalid authentication response from Laravel");
      }

      if (!payload.data) {
        throw new Error("No user data returned from Laravel authentication endpoint");
      }

      return payload.data;
    } catch (error) {
      console.error("[auth] Socket authentication failed:", error.message);
      throw error;
    }
  }
  io.use(async (socket, next) => {
    try {
      const token = authTokenFromSocket(socket);
      if (!token) {
        console.warn("[socket] handshake rejected: missing auth token (use auth: { token } or Authorization: Bearer)", socket.id);
        return next(new Error("Missing auth token"));
      }

      const userData = await authenticateSocket(socket, token);
      
      if (!userData || !userData.id) {
        console.warn("[socket] handshake rejected: invalid user data returned", socket.id);
        return next(new Error("Invalid user data"));
      }

      socket.data.user = userData;
      socket.data.token = token;
      console.log("[socket] authentication successful for user_id=%s, sid=%s", userData.id, socket.id);
      return next();
    } catch (error) {
      const msg = error.message || "Socket authentication failed";
      console.warn("[socket] handshake rejected:", msg, "| sid=", socket.id, "| laravel=", LARAVEL_API_URL);
      return next(new Error(msg));
    }
  });

  /**
   * CRITICAL FIX #2: Connection handler with comprehensive event logging
   * 
   * Issues Fixed:
   * 1. Added global error handler for socket-level errors
   * 2. Added event listener debugging middleware that logs when events are triggered
   * 3. Enhanced console logging for troubleshooting connection issues
   * 4. Proper handling of undefined payload parameters
   * 
   * The debugging middleware wraps all socket.on() calls to log when events fire,
   * which helps identify if the real problem is:
   * - Events not being sent by the client
   * - Events being sent but not received by server
   * - Events received but failing silently inside handlers
   */
  io.on("connection", async (socket) => {
    const { id: userId, room_ids: roomIds = [] } = socket.data.user;
    const token = socket.data.token;
    const inDriverMode =
      socket.data.user &&
      socket.data.user.role === "driver" &&
      Number(socket.data.user.last_login_at) === 1;

    socket.data.userId = userId;

    console.log(
      "[socket] connected user_id=%s sid=%s transport=%s driver_mode=%s",
      userId,
      socket.id,
      socket.conn?.transport?.name || "?",
      inDriverMode ? "yes" : "no"
    );

    if (!userSockets.has(userId)) {
      userSockets.set(userId, new Set());
    }
    userSockets.get(userId).add(socket.id);
    socketMeta.set(socket.id, { userId, token, roomIds });

    socket.join(`user:${userId}`);
    roomIds.forEach((roomId) => socket.join(`chat:${roomId}`));

    if (getUserOnlineCount(userId) === 1) {
      await setPresence(userId, true, true);
    }

    if (socket.data.user && socket.data.user.role === "driver") {
      socket.join(`driver:${userId}`);
    }

    socket.emit("socket:ready", {
      user_id: userId,
      room_ids: roomIds,
    });

    // Driver UI is based on role + driver mode flag from auth payload.
    let isDriver = inDriverMode;
    if (isDriver) {
      socket.data.isDriver = true;
    }

    const RIDE_VISIBILITY_MS_LOCAL = RIDE_VISIBILITY_MS;

    const emitNearbyRidesWithAutoHide = (responseData, source = "unknown", isFareUpdate = false) => {
      const count = responseData?.count ?? responseData?.data?.length ?? 0;

      if (count > 0 && Array.isArray(responseData?.data)) {
        const fareUpdated =
          isFareUpdate
          || source.includes("fare")
          || source === "fare_updated_force";
        emitShowRidesToDriver(userId, responseData.data, source, fareUpdated).catch(() => {});
        return;
      }

      if (isShowProtected(userId)) {
        console.log(
          "[socket] ↷ Skip empty emit user_id=%s (show protection, source=%s)",
          userId,
          source
        );
      }
    };

    const resetRideVisibilityForDriver = (payload, source = "unknown") => {
      if (!payload) return;

      const state = getDriverRideState(userId);
      state.hidden = false;
      if (state.hideTimer) {
        clearTimeout(state.hideTimer);
        state.hideTimer = null;
      }

      console.log(
        "[socket] 🔁 Visibility reset (internal) for driver user_id=%s ride_id=%s (source=%s)",
        userId,
        payload.ride_id,
        source
      );
    };

    socket.data.forceShowNearbyRides = async (rides, source = "fare_updated") => {
      await forceShowRidesForDriver(userId, rides, source);
    };

    socket.data.resetRideVisibilityForDriver = resetRideVisibilityForDriver;

    // Helper function to refresh nearby rides for this driver
    const refreshNearbyRides = async (options = {}) => {
      if (!socket.data.isDriver) return;
      
      try {
        const nearbyRides = await laravelFetch("/api/driver/near/by/ride", {
          method: "GET",
          headers: {
            Authorization: `Bearer ${token}`,
          },
        });

        const forcedRides = options.forceRides || [];
        const mergedRides = mergeForcedRides(nearbyRides?.data || [], forcedRides);
        const fareUpdated = options.fareUpdated === true;
        
        console.log(
          "[socket] 🔄 Refreshing nearby rides for driver user_id=%s, api_count=%d, merged_count=%d, source=%s",
          userId,
          nearbyRides?.data?.length || 0,
          mergedRides.length,
          options.source || "refresh"
        );

        const state = getDriverRideState(userId);
        if (isShowProtected(userId) && state.lastRides?.length > 0) {
          console.log("[socket] ↷ Skip refresh user_id=%s (1-min show lock)", userId);
          return;
        }
        const hasForcedRides = forcedRides.length > 0;
        const mayReshowWhileHidden =
          fareUpdated
          || hasForcedRides
          || options.source === "fare_updated"
          || options.source === "fare_updated_force"
          || options.source === "fare_updated_show"
          || options.source === "push-refresh"
          || options.source === "app-foreground";

        if (state.hidden && !mayReshowWhileHidden) {
          return;
        }

        if (hasForcedRides && mergedRides.length > 0) {
          emitNearbyRidesWithAutoHide(
            {
              success: true,
              data: mergedRides,
              count: mergedRides.length,
              timestamp: new Date().toISOString(),
            },
            options.source || "refresh",
            fareUpdated
          );
          return;
        }

        if (mergedRides.length === 0) {
          return;
        }

        if (options.source === "interval-sync") {
          return;
        }

        emitNearbyRidesWithAutoHide(
          {
            success: true,
            data: mergedRides,
            count: mergedRides.length,
            timestamp: new Date().toISOString(),
          },
          options.source || "refresh",
          fareUpdated
        );
      } catch (error) {
        console.error("[socket] ✗ Refresh nearby rides error:", error.message);

        const forcedRides = options.forceRides || [];
        if (forcedRides.length > 0) {
          emitNearbyRidesWithAutoHide({
            success: true,
            data: mergeForcedRides([], forcedRides),
            count: forcedRides.length,
            timestamp: new Date().toISOString(),
          }, options.source || "refresh-forced", true);
        }
      }
    };
    
    let driverSyncTimer = null;
    const startDriverSyncTimer = () => {
      if (driverSyncTimer) {
        clearInterval(driverSyncTimer);
        driverSyncTimer = null;
      }
      if (!socket.data.isDriver) return;

      driverSyncTimer = setInterval(() => {
        if (socket.connected && socket.data.isDriver) {
          refreshNearbyRides({ source: "interval-sync" }).catch(() => {});
        }
      }, 20000);
    };

    (async () => {
      try {
        if (isDriver) {
          console.log("[socket] ✓ Driver connected - auto-fetching nearby rides for user_id=%s", userId);
          await refreshNearbyRides();
          startDriverSyncTimer();
          return;
        }

        const userData = await laravelFetch("/api/socket/me", {
          method: "GET",
          headers: {
            Authorization: `Bearer ${token}`,
          },
        });

        if (
          userData &&
          userData.data &&
          userData.data.role === "driver" &&
          Number(userData.data.last_login_at) === 1
        ) {
          isDriver = true;
          socket.data.isDriver = true;
          console.log("[socket] ✓ Driver mode detected - auto-fetching nearby rides for user_id=%s", userId);
          await refreshNearbyRides();
          startDriverSyncTimer();
        }
      } catch (error) {
        console.error("[socket] ✗ Auto-fetch nearby rides error:", error.message);
      }
    })();
    
    // Store refresh function for use in broadcast events
    socket.data.refreshNearbyRides = refreshNearbyRides;

    if (isDriver) {
      startDriverSyncTimer();
    }

    // Global error handler for the socket
    socket.on("error", (error) => {
      console.error("[socket] socket error for user_id=%s:", userId, error);
    });

    // Log when socket events are registered for debugging
    console.log("[socket] registering event listeners for user_id=%s", userId);

    let lastActivityTouch = Date.now();

    // Add a wildcard listener to catch ANY incoming event (for debugging)
    socket.onAny((eventName, ...args) => {
      console.log("[socket] ANY EVENT RECEIVED - user_id=%s, event=%s, args count=%d", userId, eventName, args.length);

      const now = Date.now();
      if (now - lastActivityTouch >= 15000) {
        lastActivityTouch = now;
        touchActivity(userId);
      }
    });

    socket.on("chat:sync", async () => {
      console.log("[socket] chat:sync event fired - user_id=%s", userId);
      try {
        console.log("[socket] chat:sync - user_id=%s, syncing room subscriptions", userId);
        
        const userData = await authenticateSocket(socket, token);
        const nextRoomIds = userData.room_ids || [];
        socketMeta.set(socket.id, { userId, token, roomIds: nextRoomIds });
        nextRoomIds.forEach((roomId) => socket.join(`chat:${roomId}`));
        
        console.log("[socket] chat:sync - user_id=%s, joined %d rooms", userId, nextRoomIds.length);
        socket.emit("chat:sync:ok", { room_ids: nextRoomIds });
      } catch (error) {
        console.error("[socket] chat:sync error:", error.message);
        socket.emit("chat:error", { message: error.message });
      }
    });

    socket.on("chat:join", ({ room_id } = {}) => {
      console.log("[socket] chat:join event fired - user_id=%s", userId);
      if (!room_id) {
        console.warn("[socket] chat:join - missing room_id");
        return;
      }
      console.log("[socket] chat:join - user_id=%s, room_id=%s", userId, room_id);
      socket.join(`chat:${room_id}`);
    });

    socket.on("chat:typing", ({ room_id, is_typing } = {}) => {
      console.log("[socket] chat:typing event fired - user_id=%s", userId);
      if (!room_id) {
        console.warn("[socket] chat:typing - missing room_id");
        return;
      }
      console.log("[socket] chat:typing - user_id=%s, room_id=%s, is_typing=%s", userId, room_id, !!is_typing);
      socket.to(`chat:${room_id}`).emit("chat:typing", {
        room_id,
        user_id: userId,
        is_typing: !!is_typing,
      });
    });
    socket.on("chat:send", async (payload = {}, callback) => {
      console.log("[socket] chat:send event fired - user_id=%s", userId);
      try {
        if (!payload || typeof payload !== "object") {
          throw new Error("Invalid payload");
        }

        console.log("[socket] chat:send - user_id=%s, room_id=%s, ride_id=%s", userId, payload.room_id, payload.ride_id);
        
        const raw = await laravelFetch("/api/chat/messages", {
          method: "POST",
          headers: {
            Authorization: `Bearer ${token}`,
            "Content-Type": "application/json",
            Accept: "application/json",
          },
          body: JSON.stringify({
            room_id: payload.room_id ?? null,
            ride_id: payload.ride_id ?? null,
            message_type: payload.message_type || (payload.image_url || payload.image ? "image" : "text"),
            message: payload.message || null,
            image_url: payload.image_url || payload.image || null,
            image_base64: payload.image_base64 || null,
            meta: payload.meta || null,
          }),
        });

        let message = raw != null && typeof raw === "object" && Object.prototype.hasOwnProperty.call(raw, "data") ? raw.data : null;
        if (Array.isArray(message)) {
          message = message[0] ?? null;
        }

        console.log("[socket] chat:send - message sent successfully, message_id=%s", message?.id || "unknown");
        if (typeof callback === "function") {
          callback(message);
        }
      } catch (error) {
        console.error("[socket] chat:send error:", error.message);
        const errorResponse = { ok: false, message: error.message };
        if (typeof callback === "function") {
          callback(errorResponse);
        } else {
          socket.emit("chat:error", errorResponse);
        }
      }
    });

    socket.on("presence:status", ({ user_id } = {}, callback) => {
      console.log("[socket] presence:status event fired - user_id=%s", userId);
      if (!user_id) {
        console.warn("[socket] presence:status - missing user_id");
        const error = { ok: false, message: "user_id is required" };
        if (typeof callback === "function") {
          callback(error);
        } else {
          socket.emit("presence:status:error", error);
        }
        return;
      }
      
      const online = isUserOnline(Number(user_id));
      console.log("[socket] presence:status - user_id=%s, is_online=%s", user_id, online);
      
      if (typeof callback === "function") {
        callback({ user_id, is_online: online });
      } else {
        socket.emit("presence:status:result", { user_id, is_online: online });
      }
    });

    socket.on("app:foreground", async (_payload, callback) => {
      console.log("[socket] app:foreground - user_id=%s", userId);
      await setPresence(userId, true, true);

      if (socket.data.isDriver && typeof socket.data.refreshNearbyRides === "function") {
        console.log("[socket] app:foreground - refreshing nearby rides for driver user_id=%s", userId);
        await socket.data.refreshNearbyRides({ source: "app-foreground", fareUpdated: true });
      }

      const response = { ok: true, user_id: userId, is_app_foreground: true };
      if (typeof callback === "function") {
        callback(response);
      } else {
        socket.emit("app:foreground:result", response);
      }
    });

    socket.on("app:background", async (_payload, callback) => {
      console.log("[socket] app:background - user_id=%s", userId);
      await setPresence(userId, true, false);

      const response = { ok: true, user_id: userId, is_app_foreground: false };
      if (typeof callback === "function") {
        callback(response);
      } else {
        socket.emit("app:background:result", response);
      }
    });
    socket.on("driver:refresh-from-push", async (payload, callback) => {
      if (isShowProtected(userId)) {
        const locked = getDriverRideState(userId).lastRides || [];
        if (locked.length > 0) {
          await emitNearbyRidesListShow(userId, locked, "push-refresh-lock");
        }
        const response = { success: true, user_id: userId, count: locked.length };
        if (typeof callback === "function") callback(response);
        else socket.emit("driver:refresh-from-push:result", response);
        return;
      }

      const state = getDriverRideState(userId);
      state.hidden = false;
      if (state.hideTimer) {
        clearTimeout(state.hideTimer);
        state.hideTimer = null;
      }

      try {
        const raw = await laravelFetch("/api/driver/near/by/ride", {
          method: "GET",
          headers: { Authorization: `Bearer ${token}` },
        });
        const rides = raw?.data || [];
        if (rides.length > 0) {
          await emitNearbyRidesListShow(userId, rides, "push-refresh");
          scheduleDriverRideHide(userId, "push-refresh");
        } else {
          await refreshNearbyRides({ source: "push-refresh", fareUpdated: true });
        }
        const response = { success: true, user_id: userId, count: rides.length };
        if (typeof callback === "function") callback(response);
        else socket.emit("driver:refresh-from-push:result", response);
      } catch (error) {
        const errorResponse = { success: false, message: error.message };
        if (typeof callback === "function") callback(errorResponse);
        else socket.emit("driver:refresh-from-push:error", errorResponse);
      }
    });

    socket.on("driver:sync-rides", async (payload, callback) => {
      if (!socket.data.isDriver) {
        const error = { success: false, message: "Driver mode is not active" };
        if (typeof callback === "function") callback(error);
        else socket.emit("driver:sync-rides:error", error);
        return;
      }

      try {
        await refreshNearbyRides({ source: "manual-sync" });
        const response = { success: true, user_id: userId };
        if (typeof callback === "function") callback(response);
        else socket.emit("driver:sync-rides:result", response);
      } catch (error) {
        const errorResponse = { success: false, message: error.message };
        if (typeof callback === "function") callback(errorResponse);
        else socket.emit("driver:sync-rides:error", errorResponse);
      }
    });

    socket.on("driver:nearby-rides", async (payload, callback) => {
      console.log("[socket] ✓ driver:nearby-rides listener TRIGGERED - user_id=%s", userId);
      console.log("[socket] driver:nearby-rides - payload=%O, callback present=%s", payload, typeof callback === "function");
      try {
        if (isShowProtected(userId)) {
          const locked = getDriverRideState(userId).lastRides || [];
          const responseData = {
            success: true,
            data: locked,
            count: locked.length,
            hidden: false,
            visibility_seconds: RIDE_VISIBILITY_MS / 1000,
            timestamp: new Date().toISOString(),
          };
          if (typeof callback === "function") callback(responseData);
          else emitNearbyRidesWithAutoHide(responseData, "show-lock");
          return;
        }

        console.log("[socket] driver:nearby-rides - fetching from Laravel API");
        
        const raw = await laravelFetch("/api/driver/near/by/ride", {
          method: "GET",
          headers: {
            Authorization: `Bearer ${token}`,
          },
        });

        console.log("[socket] ✓ driver:nearby-rides - API response received. Data length=%d", raw?.data?.length || 0);
        
        const responseData = {
          success: true,
          data: raw?.data || [],
          count: raw?.data?.length || 0,
          timestamp: new Date().toISOString(),
        };
        
        if (typeof callback === "function") {
          console.log("[socket] driver:nearby-rides - sending response via callback (ACK)");
          callback(responseData);
          if (responseData.count > 0) {
            scheduleDriverRideHide(userId, "manual-callback");
          }
        } else {
          console.log("[socket] driver:nearby-rides - broadcasting via emit:driver:nearby-rides:list");
          emitNearbyRidesWithAutoHide(responseData, "manual");
        }
        console.log("[socket] ✓ driver:nearby-rides - response sent successfully");
      } catch (error) {
        console.error("[socket] ✗ driver:nearby-rides error:", error.message);
        const errorResponse = { 
          success: false, 
          message: error.message,
          data: [],
          count: 0,
        };
        console.log("[socket] driver:nearby-rides - sending error response=%O", errorResponse);
        if (typeof callback === "function") {
          callback(errorResponse);
        } else {
          socket.emit("driver:nearby-rides:error", errorResponse);
        }
      }
    });

    // 🚗 CURRENT RIDE SYSTEM: Auto-refresh accepted ride with real-time updates
    let currentRideInterval = null;
    let currentRideData = null;
    
    // Function to fetch and emit current ride
    const fetchAndEmitCurrentRide = async () => {
      try {
        const raw = await laravelFetch("/api/driver/ride/accept", {
          method: "GET",
          headers: {
            Authorization: `Bearer ${token}`,
          },
        });

        const hasRide = raw && raw.data && (Array.isArray(raw.data) ? raw.data.length > 0 : raw.data.id);
        currentRideData = hasRide ? raw.data : null;
        
        console.log("[socket] 🔄 Current ride refresh - user_id=%s, has_ride=%s", userId, hasRide);
        
        socket.emit("driver:current-ride:update", {
          success: true,
          data: currentRideData,
          has_ride: hasRide,
          timestamp: new Date().toISOString(),
        });

        // Adjust refresh interval based on ride status
        if (hasRide) {
          // Has accepted ride - refresh every 5 seconds for real-time updates
          if (currentRideInterval) {
            clearInterval(currentRideInterval);
          }
          currentRideInterval = setInterval(fetchAndEmitCurrentRide, 5000);
          console.log("[socket] ✓ Current ride tracking active - refreshing every 5s");
        } else {
          // No ride - check every 10 seconds in case ride gets accepted
          if (currentRideInterval) {
            clearInterval(currentRideInterval);
          }
          currentRideInterval = setInterval(fetchAndEmitCurrentRide, 10000);
          console.log("[socket] ⏳ Waiting for ride acceptance - checking every 10s");
        }

        return raw;
      } catch (error) {
        console.error("[socket] ✗ Current ride fetch error:", error.message);
        socket.emit("driver:current-ride:error", {
          success: false,
          message: error.message,
        });
        return null;
      }
    };

    socket.on("driver:get-accepted-rides", async (payload, callback) => {
      console.log("[socket] ✓ driver:get-accepted-rides listener TRIGGERED - user_id=%s", userId);
      try {
        console.log("[socket] driver:get-accepted-rides - fetching from Laravel API");
        
        const raw = await fetchAndEmitCurrentRide();

        console.log("[socket] ✓ driver:get-accepted-rides - API response received. Has ride=%s", 
          raw && raw.data ? 'yes' : 'no');
        
        if (typeof callback === "function") {
          console.log("[socket] driver:get-accepted-rides - sending via callback");
          callback({
            success: true,
            data: raw?.data || null,
            has_ride: !!(raw && raw.data),
            timestamp: new Date().toISOString(),
          });
        }
        
        console.log("[socket] ✓ driver:get-accepted-rides - response sent & auto-refresh started");
      } catch (error) {
        console.error("[socket] ✗ driver:get-accepted-rides error:", error.message);
        const errorResponse = { 
          success: false, 
          message: error.message,
          data: null,
          has_ride: false,
        };
        if (typeof callback === "function") {
          callback(errorResponse);
        } else {
          socket.emit("driver:get-accepted-rides:error", errorResponse);
        }
      }
    });

    // Manual stop current ride tracking
    socket.on("driver:stop-ride-tracking", () => {
      console.log("[socket] ✓ driver:stop-ride-tracking - user_id=%s", userId);
      if (currentRideInterval) {
        clearInterval(currentRideInterval);
        currentRideInterval = null;
        currentRideData = null;
        console.log("[socket] ✓ Current ride tracking stopped");
      }
    });

    socket.on("ride:get-bids", async (payload, callback) => {
      console.log("[socket] ✓ ride:get-bids listener TRIGGERED - user_id=%s", userId);
      const rideId = payload && (payload.ride_id || payload.rideId || payload.id);
      if (!rideId) {
        console.warn("[socket] ✗ ride:get-bids - missing ride_id in payload=%O", payload);
        const error = { ok: false, message: "ride_id is required" };
        if (typeof callback === "function") {
          callback(error);
        } else {
          socket.emit("ride:get-bids:error", error);
        }
        return;
      }

      try {
        console.log("[socket] ride:get-bids - fetching bids for ride_id=%s", rideId);
        
        const raw = await laravelFetch(`/api/get-bid/${encodeURIComponent(rideId)}`, {
          method: "GET",
          headers: {
            Authorization: `Bearer ${token}`,
          },
        });

        console.log("[socket] ✓ ride:get-bids - API response received. Bid count=%d", raw?.data?.length || 0);
        
        if (typeof callback === "function") {
          callback(raw);
        } else {
          socket.emit("ride:get-bids:result", raw);
        }
        console.log("[socket] ✓ ride:get-bids - response sent");
      } catch (error) {
        console.error("[socket] ✗ ride:get-bids error:", error.message);
        const errorResponse = { ok: false, message: error.message };
        if (typeof callback === "function") {
          callback(errorResponse);
        } else {
          socket.emit("ride:get-bids:error", errorResponse);
        }
      }
    });

    socket.on("ride:accept-bid", async (payload, callback) => {
      console.log("[socket] ✓ ride:accept-bid listener TRIGGERED - user_id=%s", userId);
      const rideId = payload && (payload.ride_id || payload.rideId);
      const bidId = payload && (payload.bid_id || payload.bidId);
      
      if (!rideId || !bidId) {
        console.warn("[socket] ✗ ride:accept-bid - missing params. rideId=%s, bidId=%s, payload=%O", rideId, bidId, payload);
        const error = { ok: false, message: "ride_id and bid_id are required" };
        if (typeof callback === "function") {
          callback(error);
        } else {
          socket.emit("ride:accept-bid:error", error);
        }
        return;
      }

      try {
        console.log("[socket] ride:accept-bid - accepting bid ride_id=%s, bid_id=%s", rideId, bidId);
        
        const raw = await laravelFetch(`/api/ride/${encodeURIComponent(rideId)}/bid/accept/${encodeURIComponent(bidId)}`, {
          method: "POST",
          headers: {
            Authorization: `Bearer ${token}`,
            "Content-Type": "application/json",
            Accept: "application/json",
          },
          body: JSON.stringify({}),
        });

        console.log("[socket] ✓ ride:accept-bid - API accepted the bid. Response=%O", raw);
        
        if (typeof callback === "function") {
          callback(raw);
        } else {
          socket.emit("ride:accept-bid:result", raw);
        }
        console.log("[socket] ✓ ride:accept-bid - response sent");
      } catch (error) {
        console.error("[socket] ✗ ride:accept-bid error:", error.message);
        const errorResponse = { ok: false, message: error.message };
        if (typeof callback === "function") {
          callback(errorResponse);
        } else {
          socket.emit("ride:accept-bid:error", errorResponse);
        }
      }
    });

    // ═══════════════════════════════════════════════════════════════════════════
    // RIDE MANAGEMENT SOCKET EVENTS (Real-time alternative to API endpoints)
    // ═══════════════════════════════════════════════════════════════════════════

    socket.on("ride:create-booking", async (payload, callback) => {
      console.log("[socket] ✓ ride:create-booking listener TRIGGERED - user_id=%s", userId);
      const required = ['start_latitude', 'start_longitude', 'end_latitude', 'end_longitude', 'start', 'destination'];
      const missing = required.filter(field => !payload || payload[field] == null);
      
      if (missing.length > 0) {
        console.warn("[socket] ✗ ride:create-booking - missing fields=%O", missing);
        const error = { ok: false, message: `Missing required fields: ${missing.join(', ')}` };
        if (typeof callback === "function") {
          callback(error);
        } else {
          socket.emit("ride:create-booking:error", error);
        }
        return;
      }

      try {
        console.log("[socket] ride:create-booking - creating ride from (%s,%s) to (%s,%s)", 
          payload.start_latitude, payload.start_longitude, payload.end_latitude, payload.end_longitude);
        
        const raw = await laravelFetch("/api/booking", {
          method: "POST",
          headers: {
            Authorization: `Bearer ${token}`,
            "Content-Type": "application/json",
            Accept: "application/json",
          },
          body: JSON.stringify({
            start_latitude: payload.start_latitude,
            start_longitude: payload.start_longitude,
            end_latitude: payload.end_latitude,
            end_longitude: payload.end_longitude,
            start: payload.start,
            destination: payload.destination,
          }),
        });

        console.log("[socket] ✓ ride:create-booking - ride created. ride_id=%s", raw?.data?.ride?.id || 'unknown');
        
        if (typeof callback === "function") {
          callback(raw);
        } else {
          socket.emit("ride:create-booking:result", raw);
        }
        console.log("[socket] ✓ ride:create-booking - response sent");
      } catch (error) {
        console.error("[socket] ✗ ride:create-booking error:", error.message);
        const errorResponse = { ok: false, message: error.message };
        if (typeof callback === "function") {
          callback(errorResponse);
        } else {
          socket.emit("ride:create-booking:error", errorResponse);
        }
      }
    });

    socket.on("ride:update-booking", async (payload, callback) => {
      console.log("[socket] ✓ ride:update-booking listener TRIGGERED - user_id=%s", userId);
      const rideId = payload && (payload.ride_id || payload.rideId || payload.id);
      const vehicleCategoryId = payload && (payload.vehicle_category_id || payload.vehicleCategoryId);
      
      if (!rideId || !vehicleCategoryId) {
        console.warn("[socket] ✗ ride:update-booking - missing params. rideId=%s, vehicleCategoryId=%s", rideId, vehicleCategoryId);
        const error = { ok: false, message: "ride_id and vehicle_category_id are required" };
        if (typeof callback === "function") {
          callback(error);
        } else {
          socket.emit("ride:update-booking:error", error);
        }
        return;
      }

      try {
        console.log("[socket] ride:update-booking - updating ride_id=%s with vehicle_category_id=%s", rideId, vehicleCategoryId);
        
        const bodyData = {
          vehicle_category_id: vehicleCategoryId,
        };
        
        if (payload.promo_code) {
          bodyData.promo_code = payload.promo_code;
        }
        
        const raw = await laravelFetch(`/api/rides/vehicle/update/${encodeURIComponent(rideId)}`, {
          method: "POST",
          headers: {
            Authorization: `Bearer ${token}`,
            "Content-Type": "application/json",
            Accept: "application/json",
          },
          body: JSON.stringify(bodyData),
        });

        console.log("[socket] ✓ ride:update-booking - ride updated. status=%s, fare=%s", raw?.data?.status, raw?.data?.estimated_fare);
        
        if (typeof callback === "function") {
          callback(raw);
        } else {
          socket.emit("ride:update-booking:result", raw);
        }
        console.log("[socket] ✓ ride:update-booking - response sent & nearby drivers notified");
      } catch (error) {
        console.error("[socket] ✗ ride:update-booking error:", error.message);
        const errorResponse = { ok: false, message: error.message };
        if (typeof callback === "function") {
          callback(errorResponse);
        } else {
          socket.emit("ride:update-booking:error", errorResponse);
        }
      }
    });

    // Driver GPS: saved in Laravel, relayed to the passenger of the active ride and back to the driver.
    socket.on("driver:location-update", async (payload, callback) => {
      const reply = (body) => {
        if (typeof callback === "function") callback(body);
      };

      try {
        const latitude = Number(payload?.latitude);
        const longitude = Number(payload?.longitude);
        if (!socket.data.isDriver || !Number.isFinite(latitude) || !Number.isFinite(longitude)) {
          return reply({ ok: false, message: "Invalid driver location" });
        }

        const info = await laravelFetch("/api/socket/internal/driver-location", {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "x-socket-secret": SOCKET_INTERNAL_SECRET,
          },
          body: JSON.stringify({
            user_id: userId,
            ride_id: payload?.ride_id ?? null,
            latitude,
            longitude,
          }),
        });

        // No active ride (cancelled/completed/not assigned): location saved, nothing relayed.
        if (!info?.active) {
          return reply({ ok: true, relayed: false });
        }

        const updatedAt = new Date().toISOString();
        const heading = payload?.heading ?? null;
        const speed = payload?.speed ?? null;

        io.to(`user:${info.passenger_id}`).emit("passenger:driver-location", {
          ride_id: info.ride_id,
          driver_id: info.driver_id,
          latitude,
          longitude,
          heading,
          speed,
          updated_at: updatedAt,
        });
        io.to(`user:${info.driver_id}`).emit("driver:location-updated", {
          ride_id: info.ride_id,
          latitude,
          longitude,
          heading,
          updated_at: updatedAt,
        });

        return reply({ ok: true, relayed: true });
      } catch (error) {
        console.error("[socket] ✗ driver:location-update user_id=%s: %s", userId, error.message);
        return reply({ ok: false, message: error.message });
      }
    });

    socket.on("ride:cancel", async (payload, callback) => {
      console.log("[socket] ✓ ride:cancel listener TRIGGERED - user_id=%s", userId);
      const rideId = payload && (payload.ride_id || payload.rideId || payload.id);
      
      if (!rideId) {
        console.warn("[socket] ✗ ride:cancel - missing ride_id in payload=%O", payload);
        const error = { ok: false, message: "ride_id is required" };
        if (typeof callback === "function") {
          callback(error);
        } else {
          socket.emit("ride:cancel:error", error);
        }
        return;
      }

      try {
        console.log("[socket] ride:cancel - canceling ride_id=%s, reason=%s", rideId, payload.reason || 'no reason');
        
        const raw = await laravelFetch(`/api/ride/cancel/${encodeURIComponent(rideId)}`, {
          method: "POST",
          headers: {
            Authorization: `Bearer ${token}`,
            "Content-Type": "application/json",
            Accept: "application/json",
          },
          body: JSON.stringify({
            reason: payload.reason || null,
          }),
        });

        console.log("[socket] ✓ ride:cancel - ride canceled successfully");
        
        if (typeof callback === "function") {
          callback(raw);
        } else {
          socket.emit("ride:cancel:result", raw);
        }
        console.log("[socket] ✓ ride:cancel - response sent & notifications dispatched");
      } catch (error) {
        console.error("[socket] ✗ ride:cancel error:", error.message);
        const errorResponse = { ok: false, message: error.message };
        if (typeof callback === "function") {
          callback(errorResponse);
        } else {
          socket.emit("ride:cancel:error", errorResponse);
        }
      }
    });

    socket.on("ride:update-bid-amount", async (payload, callback) => {
      console.log("[socket] ✓ ride:update-bid-amount listener TRIGGERED - user_id=%s", userId);
      const rideId = payload && (payload.ride_id || payload.rideId || payload.id);
      const finalFare = payload && (payload.final_fare || payload.finalFare || payload.amount);
      
      if (!rideId || finalFare == null) {
        console.warn("[socket] ✗ ride:update-bid-amount - missing params. rideId=%s, finalFare=%s", rideId, finalFare);
        const error = { ok: false, message: "ride_id and final_fare are required" };
        if (typeof callback === "function") {
          callback(error);
        } else {
          socket.emit("ride:update-bid-amount:error", error);
        }
        return;
      }

      try {
        console.log("[socket] ride:update-bid-amount - updating ride_id=%s with final_fare=%s", rideId, finalFare);
        
        const raw = await laravelFetch(`/api/rides/${encodeURIComponent(rideId)}/update-amount`, {
          method: "POST",
          headers: {
            Authorization: `Bearer ${token}`,
            "Content-Type": "application/json",
            Accept: "application/json",
          },
          body: JSON.stringify({
            final_fare: finalFare,
          }),
        });

        console.log("[socket] ✓ ride:update-bid-amount - fare updated successfully");
        
        if (typeof callback === "function") {
          callback(raw);
        } else {
          socket.emit("ride:update-bid-amount:result", raw);
        }
        console.log("[socket] ✓ ride:update-bid-amount - response sent & nearby drivers notified");
      } catch (error) {
        console.error("[socket] ✗ ride:update-bid-amount error:", error.message);
        const errorResponse = { ok: false, message: error.message };
        if (typeof callback === "function") {
          callback(errorResponse);
        } else {
          socket.emit("ride:update-bid-amount:error", errorResponse);
        }
      }
    });

    socket.on("ride:get-accepted", async (payload, callback) => {
      console.log("[socket] ✓ ride:get-accepted listener TRIGGERED - user_id=%s", userId);
      
      try {
        console.log("[socket] ride:get-accepted - fetching accepted rides for passenger");
        
        const raw = await laravelFetch("/api/rides/accept", {
          method: "GET",
          headers: {
            Authorization: `Bearer ${token}`,
          },
        });

        console.log("[socket] ✓ ride:get-accepted - API response received. Rides count=%d", raw?.data?.length || 0);
        
        if (typeof callback === "function") {
          callback(raw);
        } else {
          socket.emit("ride:get-accepted:result", raw);
        }
        console.log("[socket] ✓ ride:get-accepted - response sent");
      } catch (error) {
        console.error("[socket] ✗ ride:get-accepted error:", error.message);
        const errorResponse = { ok: false, message: error.message };
        if (typeof callback === "function") {
          callback(errorResponse);
        } else {
          socket.emit("ride:get-accepted:error", errorResponse);
        }
      }
    });

    socket.on("ride:apply-promo", async (payload, callback) => {
      console.log("[socket] ✓ ride:apply-promo listener TRIGGERED - user_id=%s", userId);
      const rideId = payload && (payload.ride_id || payload.rideId || payload.id);
      const promoCode = payload && (payload.promo_code || payload.promoCode);
      
      if (!rideId || !promoCode) {
        console.warn("[socket] ✗ ride:apply-promo - missing params. rideId=%s, promoCode=%s", rideId, promoCode);
        const error = { ok: false, message: "ride_id and promo_code are required" };
        if (typeof callback === "function") {
          callback(error);
        } else {
          socket.emit("ride:apply-promo:error", error);
        }
        return;
      }

      try {
        console.log("[socket] ride:apply-promo - applying promo '%s' to ride_id=%s", promoCode, rideId);
        
        const raw = await laravelFetch(`/api/apply-promo/${encodeURIComponent(rideId)}`, {
          method: "POST",
          headers: {
            Authorization: `Bearer ${token}`,
            "Content-Type": "application/json",
            Accept: "application/json",
          },
          body: JSON.stringify({
            promo_code: promoCode,
          }),
        });

        console.log("[socket] ✓ ride:apply-promo - promo applied. New fare calculations received");
        
        if (typeof callback === "function") {
          callback(raw);
        } else {
          socket.emit("ride:apply-promo:result", raw);
        }
        console.log("[socket] ✓ ride:apply-promo - response sent");
      } catch (error) {
        console.error("[socket] ✗ ride:apply-promo error:", error.message);
        const errorResponse = { ok: false, message: error.message };
        if (typeof callback === "function") {
          callback(errorResponse);
        } else {
          socket.emit("ride:apply-promo:error", errorResponse);
        }
      }
    });

    socket.on("ride:get-by-id", async (payload, callback) => {
      console.log("[socket] ✓ ride:get-by-id listener TRIGGERED - user_id=%s", userId);
      const rideId = payload && (payload.ride_id || payload.rideId || payload.id);
      
      if (!rideId) {
        console.warn("[socket] ✗ ride:get-by-id - missing ride_id in payload=%O", payload);
        const error = { ok: false, message: "ride_id is required" };
        if (typeof callback === "function") {
          callback(error);
        } else {
          socket.emit("ride:get-by-id:error", error);
        }
        return;
      }

      try {
        console.log("[socket] ride:get-by-id - fetching ride details for ride_id=%s", rideId);
        
        const raw = await laravelFetch(`/api/ride/${encodeURIComponent(rideId)}`, {
          method: "GET",
          headers: {
            Authorization: `Bearer ${token}`,
          },
        });

        console.log("[socket] ✓ ride:get-by-id - ride details received");
        
        if (typeof callback === "function") {
          callback(raw);
        } else {
          socket.emit("ride:get-by-id:result", raw);
        }
        console.log("[socket] ✓ ride:get-by-id - response sent");
      } catch (error) {
        console.error("[socket] ✗ ride:get-by-id error:", error.message);
        const errorResponse = { ok: false, message: error.message };
        if (typeof callback === "function") {
          callback(errorResponse);
        } else {
          socket.emit("ride:get-by-id:error", errorResponse);
        }
      }
    });

    socket.on("ride:get-history", async (payload, callback) => {
      console.log("[socket] ✓ ride:get-history listener TRIGGERED - user_id=%s", userId);
      
      try {
        console.log("[socket] ride:get-history - fetching ride history (completed/canceled)");
        
        const raw = await laravelFetch("/api/ride/get/by/user", {
          method: "GET",
          headers: {
            Authorization: `Bearer ${token}`,
          },
        });

        console.log("[socket] ✓ ride:get-history - history received. Rides count=%d", raw?.data?.length || 0);
        
        if (typeof callback === "function") {
          callback(raw);
        } else {
          socket.emit("ride:get-history:result", raw);
        }
        console.log("[socket] ✓ ride:get-history - response sent");
      } catch (error) {
        console.error("[socket] ✗ ride:get-history error:", error.message);
        const errorResponse = { ok: false, message: error.message };
        if (typeof callback === "function") {
          callback(errorResponse);
        } else {
          socket.emit("ride:get-history:error", errorResponse);
        }
      }
    });

    // ═══════════════════════════════════════════════════════════════════════════
    // END OF RIDE MANAGEMENT EVENTS
    // ═══════════════════════════════════════════════════════════════════════════

    socket.on("disconnect", async (reason) => {
      console.log("[socket] disconnected sid=%s reason=%s user_id=%s", socket.id, reason, userId);
      if (driverSyncTimer) {
        clearInterval(driverSyncTimer);
        driverSyncTimer = null;
      }
      const meta = socketMeta.get(socket.id);
      if (!meta) return;

      const set = userSockets.get(meta.userId);
      if (set) {
        set.delete(socket.id);
        if (set.size === 0) {
          clearDriverRideState(meta.userId);
          userSockets.delete(meta.userId);
          await setPresence(meta.userId, false, false);
        }
      }

      socketMeta.delete(socket.id);
    });
  });

  app.post("/internal/chat-started", (req, res) => {
    const providedSecret = req.headers["x-socket-secret"];
    if (!SOCKET_INTERNAL_SECRET || providedSecret !== SOCKET_INTERNAL_SECRET) {
      return res.status(401).json({ ok: false, message: "Unauthorized" });
    }

    const { chat_room_id, ride_id, passenger_id, driver_id } = req.body || {};
    if (!chat_room_id || !passenger_id || !driver_id) {
      return res.status(422).json({ ok: false, message: "chat_room_id, passenger_id, driver_id are required." });
    }

    const payload = {
      room_id: chat_room_id,
      ride_id: ride_id || null,
      passenger_id,
      driver_id,
      started_at: new Date().toISOString(),
    };

    io.to(`user:${passenger_id}`).emit("chat:started", payload);
    io.to(`user:${driver_id}`).emit("chat:started", payload);
    io.to(`chat:${chat_room_id}`).emit("chat:started", payload);

    res.json({ ok: true });
  });

  app.post("/internal/broadcast-chat-message", (req, res) => {
    const providedSecret = req.headers["x-socket-secret"];
    if (!SOCKET_INTERNAL_SECRET || providedSecret !== SOCKET_INTERNAL_SECRET) {
      return res.status(401).json({ ok: false, message: "Unauthorized" });
    }

    const body = req.body || {};
    const message = body.message;
    const roomId =
      (message && (message.chat_room_id ?? message.chatRoomId)) ?? body.chat_room_id;

    if (!message || roomId == null) {
      return res.status(422).json({ ok: false, message: "chat_room_id and message are required." });
    }

    io.to(`chat:${roomId}`).emit("chat:new-message", message);
    res.json({ ok: true });
  });

  // ═══════════════════════════════════════════════════════════════════════════
  // INTERNAL BROADCAST ENDPOINT (Called by Laravel to trigger socket events)
  // ═══════════════════════════════════════════════════════════════════════════
  app.post("/internal/broadcast", async (req, res) => {
    const providedSecret = req.headers["x-socket-secret"];
    if (!SOCKET_INTERNAL_SECRET || providedSecret !== SOCKET_INTERNAL_SECRET) {
      console.warn("[socket] ✗ Unauthorized broadcast attempt from IP:", req.ip);
      return res.status(401).json({ ok: false, message: "Unauthorized" });
    }

    const { event, data, user_ids, refresh_drivers } = req.body || {};
    
    if (!event) {
      return res.status(422).json({ ok: false, message: "event is required" });
    }

    console.log("[socket] 📡 Broadcasting event=%s to user_ids=%O, refresh_drivers=%s", 
      event, user_ids || 'all connected', refresh_drivers || false);

    try {
      // Put both sides of a ride in ride:{id}; drop them when it is cancelled/completed.
      const roomRideId = data?.ride_id ?? data?.ride?.ride_id ?? null;
      if (roomRideId && Array.isArray(user_ids) && user_ids.length > 0 && event.startsWith("ride:")) {
        const finished = event === "ride:cancelled" || event === "ride:completed";
        for (const uid of user_ids) {
          const sockets = io.in(`user:${uid}`);
          if (finished) sockets.socketsLeave(`ride:${roomRideId}`);
          else sockets.socketsJoin(`ride:${roomRideId}`);
        }
      }

      const skipRawEmit = refresh_drivers && RIDE_LIST_REFRESH_EVENTS.has(event);

      // Canceled / accepted ride: remove it from driver lists, never re-show it.
      if (skipRawEmit && isRideRemovalBroadcast(data)) {
        const purgedCount = await purgeClosedRidesFromDrivers(data);
        console.log("[socket] ✓ closed ride removed from %d driver lists", purgedCount);

        return res.json({
          ok: true,
          event: "driver:nearby-rides:list",
          drivers_refreshed: 'yes',
          drivers_count: purgedCount,
          message: `closed ride removed from ${purgedCount} driver lists`,
        });
      }

      if (skipRawEmit) {
        console.log("[socket] ↷ Skipping raw emit for %s — will send driver:nearby-rides:list only", event);
      } else if (user_ids && Array.isArray(user_ids) && user_ids.length > 0) {
        // Broadcast to specific users — room + direct socket (same as first ride)
        let sentCount = 0;
        const sockets = await io.fetchSockets();

        for (const userId of user_ids) {
          const count = getUserOnlineCount(userId);
          io.to(`user:${userId}`).emit(event, data || {});

          let directCount = 0;
          for (const sock of sockets) {
            const meta = socketMeta.get(sock.id);
            if (!meta || Number(meta.userId) !== Number(userId)) continue;
            sock.emit(event, data || {});
            directCount++;
          }

          if (count > 0 || directCount > 0) {
            sentCount++;
            if (event === "driver:nearby-rides:list" && Number(data?.count) > 0) {
              lockDriverRideShow(userId, data.data || [], "driver:nearby-rides:list");
            }
            console.log(
              "[socket] ✓ Sent event=%s to user_id=%s (connections=%d direct=%d count=%s hidden=%s ride_id=%s)",
              event,
              userId,
              count,
              directCount,
              data?.count,
              data?.hidden,
              data?.data?.[0]?.id ?? data?.data?.[0]?.ride_id ?? data?.ride_id ?? null
            );
          } else {
            console.log("[socket] ⚠ Skipped user_id=%s (offline)", userId);
          }
        }

        if (refresh_drivers) {
          console.log("[socket] 🔄 Building driver:nearby-rides:list for online drivers");
          const driverRefreshCount = await refreshConnectedDriverSockets(data, event);
          console.log("[socket] ✓ driver:nearby-rides:list sent to %d drivers", driverRefreshCount);
        }

        return res.json({
          ok: true,
          event,
          targeted_users: user_ids.length,
          sent_to: skipRawEmit ? 0 : sentCount,
          drivers_refreshed: refresh_drivers ? 'yes' : 'no',
          message: refresh_drivers
            ? `driver:nearby-rides:list refreshed for online drivers`
            : `Event sent to ${sentCount}/${user_ids.length} online users`,
        });
      } else if (!skipRawEmit) {
        // Broadcast to all connected clients
        io.emit(event, data || {});
        const totalConnections = io.engine.clientsCount;
        console.log("[socket] ✓ Broadcast event=%s to all clients (count=%d)", event, totalConnections);

        if (refresh_drivers) {
          console.log("[socket] 🔄 Building driver:nearby-rides:list for online drivers");
          const driverRefreshCount = await refreshConnectedDriverSockets(data, event);
          console.log("[socket] ✓ driver:nearby-rides:list sent to %d drivers", driverRefreshCount);
        }

        return res.json({
          ok: true,
          event,
          broadcast: 'all',
          total_connections: totalConnections,
          drivers_refreshed: refresh_drivers ? 'yes' : 'no',
          message: refresh_drivers
            ? `driver:nearby-rides:list refreshed for online drivers`
            : `Event broadcast to all connected clients`,
        });
      }

      if (refresh_drivers) {
        console.log("[socket] 🔄 Building driver:nearby-rides:list for online drivers");
        const driverRefreshCount = await refreshConnectedDriverSockets(data, event);
        console.log("[socket] ✓ driver:nearby-rides:list sent to %d drivers", driverRefreshCount);

        return res.json({
          ok: true,
          event: "driver:nearby-rides:list",
          drivers_refreshed: 'yes',
          drivers_count: driverRefreshCount,
          message: `driver:nearby-rides:list sent to ${driverRefreshCount} drivers`,
        });
      }

      return res.json({ ok: true, event, message: "No action taken" });
    } catch (error) {
      console.error("[socket] ✗ Broadcast error:", error.message);
      return res.status(500).json({ ok: false, message: error.message });
    }
  });

  app.use((req, res) => {
    if (req.method === "GET" && req.path.startsWith("/http")) {
      return res.status(400).json({
        ok: false,
        message:
          'The socket server URL was used as an HTTP path (e.g. /http://...) — browsers and HTTP clients cannot connect that way.',
        fix: [
          'Use Socket.IO client: io("http://HOST:PORT", { auth: { token } })',
          'Do not do: axios.get(API_BASE + SOCKET_URL) or path: SOCKET_URL inside io(API_BASE).',
        ],
        health_check: `${req.protocol}://${req.get("host")}/health`,
      });
    }

    res.status(404).json({
      ok: false,
      message: `Nothing here for ${req.method} ${req.path}`,
      health: "/health",
      docs: "/ — service info including client usage",
    });
  });

  server.listen(SOCKET_PORT, () => {
    console.log(`
  ╔════════════════════════════════════════════════════════════════════════════╗
  ║                  ✓ Socket.IO Server Ready                                  ║
  ╠════════════════════════════════════════════════════════════════════════════╣
  ║                                                                            ║
  ║  PORT:              ${SOCKET_PORT}
  ║  LARAVEL API BASE:  ${LARAVEL_API_URL}
  ║                                                                            ║
  ║  FULL DEBUGGING ENABLED - Server logs every socket event                   ║
  ║  Look for: "[socket] ANY EVENT RECEIVED" for incoming events               ║
  ║  Look for: "[socket] ✓ <event-name> listener TRIGGERED"                    ║
  ║                                                                            ║
  ║  REAL-TIME EVENT FLOW:                                                     ║
  ║  1. Client sends: socket.emit("driver:nearby-rides", {}, callback)          ║
  ║  2. Server logs: [socket] ANY EVENT RECEIVED                               ║
  ║  3. Server logs: [socket] ✓ driver:nearby-rides listener TRIGGERED         ║
  ║  4. Server logs: [socket] driver:nearby-rides - fetching from Laravel API  ║
  ║  5. Server logs: [socket] ✓ driver:nearby-rides - API response received    ║
  ║  6. Server logs: [socket] ✓ driver:nearby-rides - response sent            ║
  ║  7. Client receives response via callback                                   ║
  ║                                                                            ║
  ║  TROUBLESHOOTING STEPS:                                                    ║
  ║  ─────────────────────                                                     ║
  ║  If no "ANY EVENT RECEIVED" log:                                           ║
  ║    → Event not sent by client                                              ║
  ║    → Client using wrong socket instance                                    ║
  ║    → Network connectivity issue                                            ║
  ║                                                                            ║
  ║  If "ANY EVENT" shows but no "TRIGGERED":                                  ║
  ║    → Event name mismatch (check spelling exactly)                          ║
  ║    → Event handler not registered                                          ║
  ║                                                                            ║
  ║  If shows "TRIGGERED" but no "API response":                               ║
  ║    → Laravel endpoint not responding (check LARAVEL_API_URL)               ║
  ║    → Authentication token invalid                                          ║
  ║    → Network error reaching Laravel                                        ║
  ║                                                                            ║
  ╚════════════════════════════════════════════════════════════════════════════╝
    `);
  });
