import React, { useState, useEffect, useMemo } from "react";
import CimsLayout from "@/Layouts/CimsLayout";
import { Head } from "@inertiajs/react";
import axios from "axios";

export default function TopologyMap({ auth, topologyData: initialData }) {
    const [data, setData] = useState(initialData || { nodes: [], links: [], stats: {} });
    const [selectedNode, setSelectedNode] = useState(null);
    const [filterType, setFilterType] = useState("all");
    const [searchQuery, setSearchQuery] = useState("");
    const [isRefreshing, setIsRefreshing] = useState(false);
    // Pan & Zoom controls state
    const [zoom, setZoom] = useState(1);
    const [pan, setPan] = useState({ x: 0, y: 0 });
    const [isDragging, setIsDragging] = useState(false);
    const [dragStart, setDragStart] = useState({ x: 0, y: 0 });

    const handleZoomIn = () => setZoom((prev) => Math.min(prev + 0.25, 2.5));
    const handleZoomOut = () => setZoom((prev) => Math.max(prev - 0.25, 0.4));
    const handleResetZoom = () => {
        setZoom(1);
        setPan({ x: 0, y: 0 });
    };

    const handleWheel = (e) => {
        const zoomFactor = e.deltaY < 0 ? 1.1 : 0.9;
        setZoom((prev) => Math.min(Math.max(prev * zoomFactor, 0.4), 2.5));
    };

    const handleMouseDown = (e) => {
        // Start dragging canvas
        setIsDragging(true);
        setDragStart({ x: e.clientX - pan.x, y: e.clientY - pan.y });
    };

    const handleMouseMove = (e) => {
        if (isDragging) {
            setPan({
                x: e.clientX - dragStart.x,
                y: e.clientY - dragStart.y,
            });
        }
    };

    const handleMouseUp = () => setIsDragging(false);

    const refreshData = async () => {
        setIsRefreshing(true);
        try {
            const res = await axios.get(route("topology.data"));
            setData(res.data);
        } catch (e) {
            console.error("Failed loading topology data", e);
        } finally {
            setIsRefreshing(false);
        }
    };

    // Filtered nodes based on search and category filter
    const filteredNodes = useMemo(() => {
        return (data.nodes || []).filter((node) => {
            const matchesSearch =
                (node.name || "").toLowerCase().includes(searchQuery.toLowerCase()) ||
                (node.ip || "").toLowerCase().includes(searchQuery.toLowerCase()) ||
                (node.building || "").toLowerCase().includes(searchQuery.toLowerCase());

            if (filterType === "all") return matchesSearch;
            if (filterType === "discovered") return matchesSearch && node.is_discovered;
            return matchesSearch && node.type === filterType;
        });
    }, [data.nodes, searchQuery, filterType]);

    // Calculate node positions in a Tiered Hierarchical Layout with Auto-Staggering
    const positionedNodes = useMemo(() => {
        const nodes = [...filteredNodes];
        if (nodes.length === 0) return {};

        const canvasWidth = 1400;
        const positions = {};

        // Helper function to layout array of nodes into staggered rows (max 4 per row with generous spacing)
        const layoutTier = (tierNodes, startY) => {
            if (tierNodes.length === 0) return startY;
            const maxPerRow = 4;
            const rowsCount = Math.ceil(tierNodes.length / maxPerRow);

            tierNodes.forEach((node, idx) => {
                const row = Math.floor(idx / maxPerRow);
                const col = idx % maxPerRow;
                const itemsInThisRow = Math.min(maxPerRow, tierNodes.length - row * maxPerRow);
                const spacing = canvasWidth / (itemsInThisRow + 1);
                const x = spacing * (col + 1);
                const y = startY + row * 160;
                positions[node.id] = { x, y, ...node };
            });

            return startY + rowsCount * 160;
        };

        // Group nodes into logical tiers (Supports MULTIPLE ISP Gateways & MULTIPLE Core Routers!)
        const internetNodes = nodes.filter((n) => n.is_internet || n.type === "internet");
        const coreNodes = nodes.filter((n) => n.is_core);

        const coreIds = coreNodes.map((n) => n.id);
        const internetIds = internetNodes.map((n) => n.id);

        // Discovered Neighbors & Infrastructure (Switches, APs)
        const infraNodes = nodes.filter(
            (n) => !internetIds.includes(n.id) && !coreIds.includes(n.id) && (n.is_discovered || n.type === "switch" || n.type === "access_point")
        );

        // Registered Inventory & End Devices
        const remainingNodes = nodes.filter(
            (n) => !internetIds.includes(n.id) && !coreIds.includes(n.id) && !infraNodes.includes(n)
        );

        // Tier 0: ISP Clouds (start Y = 70)
        let tier1StartY = layoutTier(internetNodes, 70);

        // Tier 1: Core Routers (start Y = tier1StartY + 150)
        let tier2StartY = layoutTier(coreNodes, internetNodes.length > 0 ? tier1StartY + 150 : 200);

        // Tier 2: Infrastructure & Discovered Neighbors (start Y = tier2StartY + 160)
        let tier3StartY = layoutTier(infraNodes, tier2StartY + 160);

        // Tier 3: Inventory Devices (start Y = tier3StartY + 160)
        layoutTier(remainingNodes, tier3StartY + 160);

        // Fallback for any unpositioned nodes
        nodes.forEach((node, idx) => {
            if (!positions[node.id]) {
                positions[node.id] = { x: 200 + (idx * 280) % 1000, y: 750, ...node };
            }
        });

        return positions;
    }, [filteredNodes]);

    // Node icon helper
    const getNodeIcon = (type, isCore, isInternet) => {
        if (isInternet) return "🌐";
        if (isCore) return "🔒";
        switch (type) {
            case "router":
                return "🛜";
            case "switch":
                return "🔀";
            case "access_point":
                return "📡";
            case "server":
                return "🖥️";
            case "firewall":
                return "🛡️";
            default:
                return "💻";
        }
    };

    // Node Role Badge metadata
    const getNodeRoleInfo = (node) => {
        if (node.is_internet) return { label: "ISP GATEWAY", fill: "#06b6d4", bg: "rgba(6, 182, 212, 0.25)" };
        if (node.is_core) return { label: "CORE ROUTER", fill: "#10b981", bg: "rgba(16, 185, 129, 0.25)" };
        if (node.type === "switch") return { label: "SWITCH", fill: "#8b5cf6", bg: "rgba(139, 92, 246, 0.25)" };
        if (node.type === "access_point") return { label: "ACCESS POINT", fill: "#0284c7", bg: "rgba(2, 132, 199, 0.25)" };
        if (node.type === "server") return { label: "SERVER", fill: "#f59e0b", bg: "rgba(245, 158, 11, 0.25)" };
        if (node.is_discovered) return { label: "MNDP DISCOVERED", fill: "#3b82f6", bg: "rgba(59, 130, 246, 0.25)" };
        return { label: "CIMS INVENTORY", fill: "#64748b", bg: "rgba(100, 116, 139, 0.25)" };
    };

    return (
        <CimsLayout>
            <Head title="Interactive Network Topology Map" />

            <div className="space-y-6">
                {/* Header Section */}
                <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-white border border-slate-200 p-6 rounded-2xl">
                    <div className="flex items-center space-x-4">
                        <div className="h-12 w-12 rounded-xl bg-blue-50 border border-blue-200 flex items-center justify-center text-blue-600 font-bold text-2xl">
                            🕸️
                        </div>
                        <div>
                            <div className="flex items-center space-x-3">
                                <h1 className="text-2xl font-bold text-slate-900 tracking-wide">
                                    Peta Topologi Jaringan Live
                                </h1>
                                <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                    <span className="h-2 w-2 rounded-full bg-emerald-500 mr-1.5 animate-ping"></span>
                                    Tata Letak Hirarki
                                </span>
                            </div>
                            <p className="text-sm text-slate-500 mt-1">
                                Pemetaan visual otomatis: Sumber WAN ISP → Core Router → Switch & Access Point → Perangkat Pengguna
                            </p>
                        </div>
                    </div>

                    <button
                        onClick={refreshData}
                        disabled={isRefreshing}
                        className="flex items-center justify-center space-x-2 px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-xl text-sm transition duration-200 disabled:opacity-50 shadow-sm"
                    >
                        <svg
                            className={`w-4 h-4 ${isRefreshing ? "animate-spin" : ""}`}
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                strokeWidth="2"
                                d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
                            />
                        </svg>
                        <span>{isRefreshing ? "Pindai Topologi..." : "Pindai Ulang Topologi"}</span>
                    </button>
                </div>

                {/* Summary Stat Cards */}
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <div className="bg-white border border-slate-200 p-4 rounded-2xl flex items-center justify-between">
                        <div>
                            <div className="text-xs text-slate-500 uppercase font-semibold">Total Perangkat</div>
                            <div className="text-2xl font-bold text-slate-900 mt-1">{data.stats?.total_nodes || 0}</div>
                        </div>
                        <div className="p-3 bg-purple-50 rounded-xl text-purple-700 font-bold">🗺️</div>
                    </div>

                    <div className="bg-white border border-slate-200 p-4 rounded-2xl flex items-center justify-between">
                        <div>
                            <div className="text-xs text-slate-500 uppercase font-semibold">Perangkat Online</div>
                            <div className="text-2xl font-bold text-emerald-700 mt-1">{data.stats?.online_nodes || 0}</div>
                        </div>
                        <div className="p-3 bg-emerald-50 rounded-xl text-emerald-700 font-bold">🟢</div>
                    </div>

                    <div className="bg-white border border-slate-200 p-4 rounded-2xl flex items-center justify-between">
                        <div>
                            <div className="text-xs text-slate-500 uppercase font-semibold">Terdeteksi (MNDP)</div>
                            <div className="text-2xl font-bold text-blue-700 mt-1">{data.stats?.discovered_neighbors || 0}</div>
                        </div>
                        <div className="p-3 bg-blue-50 rounded-xl text-blue-700 font-bold">📡</div>
                    </div>

                    <div className="bg-white border border-slate-200 p-4 rounded-2xl flex items-center justify-between">
                        <div>
                            <div className="text-xs text-slate-500 uppercase font-semibold">Koneksi Aktif</div>
                            <div className="text-2xl font-bold text-amber-700 mt-1">{data.stats?.total_links || 0}</div>
                        </div>
                        <div className="p-3 bg-amber-50 rounded-xl text-amber-700 font-bold">🔗</div>
                    </div>
                </div>

                {/* Search & Filter Toolbar */}
                <div className="flex flex-col sm:flex-row items-center justify-between gap-3 bg-white border border-slate-200 p-4 rounded-2xl">
                    {/* Search Input */}
                    <div className="relative w-full sm:w-80">
                        <input
                            type="text"
                            placeholder="Cari berdasarkan nama, IP, atau lokasi..."
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            className="w-full pl-10 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm text-slate-800 placeholder-slate-400 focus:bg-white focus:outline-none focus:border-blue-600 focus:ring-blue-600"
                        />
                        <svg
                            className="w-4 h-4 text-slate-400 absolute left-3 top-3"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                strokeWidth="2"
                                d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                            />
                        </svg>
                    </div>

                    {/* Filter Pills */}
                    <div className="flex items-center space-x-1.5 overflow-x-auto w-full sm:w-auto">
                        {[
                            { id: "all", label: "Semua Perangkat" },
                            { id: "router", label: "Router" },
                            { id: "switch", label: "Switch" },
                            { id: "access_point", label: "Access Point" },
                            { id: "server", label: "Server" },
                            { id: "discovered", label: "Terdeteksi MNDP" },
                        ].map((filter) => (
                            <button
                                key={filter.id}
                                onClick={() => setFilterType(filter.id)}
                                className={`px-3 py-1.5 rounded-lg text-xs font-semibold whitespace-nowrap transition ${
                                    filterType === filter.id
                                        ? "bg-blue-600 text-white shadow-sm"
                                        : "bg-slate-50 text-slate-600 border border-slate-200 hover:text-slate-900 hover:bg-slate-100"
                                }`}
                            >
                                {filter.label}
                            </button>
                        ))}
                    </div>
                </div>

                {/* Main Interactive Canvas & Drawer Area */}
                <div className="grid grid-cols-1 lg:grid-cols-4 gap-6">
                    {/* SVG Interactive Visualizer Canvas */}
                    <div className="lg:col-span-3 bg-white border border-slate-200 rounded-2xl p-4 overflow-hidden relative min-h-[720px] flex items-center justify-center">
                        {/* Floating Zoom / Pan Controls Toolbar */}
                        <div className="absolute top-4 right-4 bg-white/95 border border-slate-200 backdrop-blur-md p-1.5 rounded-xl flex items-center space-x-1.5 shadow-md z-10 select-none">
                            <button
                                onClick={handleZoomIn}
                                className="p-1.5 hover:bg-brand-bgSecondary rounded-lg text-slate-900 text-xs font-bold transition flex items-center space-x-1"
                                title="Zoom In (+)"
                            >
                                <span>🔍</span>
                                <span>+</span>
                            </button>
                            <button
                                onClick={handleZoomOut}
                                className="p-1.5 hover:bg-brand-bgSecondary rounded-lg text-slate-900 text-xs font-bold transition flex items-center space-x-1"
                                title="Zoom Out (-)"
                            >
                                <span>🔍</span>
                                <span>-</span>
                            </button>
                            <span className="px-2 text-xs font-mono text-emerald-700 font-bold border-l border-r border-brand-border">
                                {Math.round(zoom * 100)}%
                            </span>
                            <button
                                onClick={handleResetZoom}
                                className="px-2.5 py-1 bg-purple-50 border border-purple-200 hover:bg-purple-600 text-purple-700 rounded-lg text-xs font-semibold transition"
                                title="Reset Zoom & Pan to Fit Screen"
                            >
                                🎯 Fit View
                            </button>
                        </div>

                        <svg
                            className={`w-full h-[760px] select-none ${isDragging ? "cursor-grabbing" : "cursor-grab"}`}
                            viewBox="0 0 1400 950"
                            onWheel={handleWheel}
                            onMouseDown={handleMouseDown}
                            onMouseMove={handleMouseMove}
                            onMouseUp={handleMouseUp}
                            onMouseLeave={handleMouseUp}
                        >
                            <defs>
                                {/* Glowing Filter for Links & Core Nodes */}
                                <filter id="glow-emerald" x="-30%" y="-30%" width="160%" height="160%">
                                    <feGaussianBlur stdDeviation="4" result="blur" />
                                    <feComposite in="SourceGraphic" in2="blur" operator="over" />
                                </filter>

                                <filter id="glow-cyan" x="-30%" y="-30%" width="160%" height="160%">
                                    <feGaussianBlur stdDeviation="4" result="blur" />
                                    <feComposite in="SourceGraphic" in2="blur" operator="over" />
                                </filter>

                                {/* Gradient line pattern */}
                                <linearGradient id="linkGradient" x1="0%" y1="0%" x2="100%" y2="100%">
                                    <stop offset="0%" stopColor="#10b981" />
                                    <stop offset="100%" stopColor="#8b5cf6" />
                                </linearGradient>
                            </defs>

                            {/* Background Grid Pattern */}
                            <pattern id="gridPattern" width="40" height="40" patternUnits="userSpaceOnUse">
                                <path d="M 40 0 L 0 0 0 40" fill="none" stroke="rgba(255,255,255,0.03)" strokeWidth="1" />
                            </pattern>
                            <rect width="100%" height="100%" fill="url(#gridPattern)" />

                            {/* Zoom & Pan Scalable Container Group */}
                            <g transform={`translate(${pan.x}, ${pan.y}) scale(${zoom})`}>
                                {/* Render Links (Connection Lines) */}
                                {(data.links || []).map((link) => {
                                    const sourcePos = positionedNodes[link.source];
                                    const targetPos = positionedNodes[link.target];

                                    if (!sourcePos || !targetPos) return null;

                                    const isSelected =
                                        selectedNode &&
                                        (selectedNode.id === link.source || selectedNode.id === link.target);

                                    // Smooth curved Bezier path
                                    const midY = (sourcePos.y + targetPos.y) / 2;
                                    const pathD = `M ${sourcePos.x} ${sourcePos.y} C ${sourcePos.x} ${midY}, ${targetPos.x} ${midY}, ${targetPos.x} ${targetPos.y}`;

                                    // Badge position at 35% along the link curve (closer to source)
                                    const badgeX = sourcePos.x * 0.65 + targetPos.x * 0.35;
                                    const badgeY = sourcePos.y * 0.65 + targetPos.y * 0.35;

                                    return (
                                        <g key={link.id}>
                                            {/* Outer Connection Path */}
                                            <path
                                                d={pathD}
                                                fill="none"
                                                stroke={isSelected ? "#10b981" : link.source === "isp-internet-cloud" ? "#06b6d4" : "rgba(139, 92, 246, 0.35)"}
                                                strokeWidth={isSelected ? 4 : link.source === "isp-internet-cloud" ? 3 : 2}
                                                strokeDasharray={isSelected ? "8 4" : link.source === "isp-internet-cloud" ? "none" : "6 4"}
                                                className={isSelected ? "animate-pulse" : ""}
                                            />

                                            {/* Interface Badge along the Link (Only if explicit interface) */}
                                            {link.source_interface && (
                                                <g transform={`translate(${badgeX}, ${badgeY})`}>
                                                    <rect
                                                        x="-35"
                                                        y="-10"
                                                        width="70"
                                                        height="16"
                                                        rx="4"
                                                        fill="rgba(15, 23, 42, 0.9)"
                                                        stroke="rgba(255,255,255,0.2)"
                                                        strokeWidth="1"
                                                    />
                                                    <text
                                                        x="0"
                                                        y="2"
                                                        fill="#38bdf8"
                                                        fontSize="9"
                                                        fontWeight="bold"
                                                        fontFamily="monospace"
                                                        textAnchor="middle"
                                                    >
                                                        {link.source_interface}
                                                    </text>
                                                </g>
                                            )}
                                        </g>
                                    );
                                })}

                                {/* Render Nodes */}
                                {Object.values(positionedNodes).map((node) => {
                                    const isSelected = selectedNode?.id === node.id;
                                    const isCore = node.is_core;
                                    const isInternet = node.is_internet;
                                    const roleInfo = getNodeRoleInfo(node);
                                    const isLarge = isCore || isInternet;

                                    // Clean name formatting to prevent line overlap
                                    const rawName = node.name || "Device";
                                    const truncatedName = rawName.length > 22 ? rawName.substring(0, 20) + "…" : rawName;

                                    return (
                                        <g
                                            key={node.id}
                                            transform={`translate(${node.x}, ${node.y})`}
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                setSelectedNode(node);
                                            }}
                                            className="cursor-pointer group"
                                        >
                                            <title>{rawName} [{node.ip || 'N/A'}]</title>

                                            {/* Core / Internet Node Pulse Ring */}
                                            {isLarge && (
                                                <circle
                                                    r="42"
                                                    fill="none"
                                                    stroke={roleInfo.fill}
                                                    strokeWidth="1.5"
                                                    className="animate-ping opacity-30"
                                                />
                                            )}

                                            {/* Node Circle Container */}
                                            <circle
                                                r={isSelected ? (isLarge ? 38 : 30) : (isLarge ? 34 : 26)}
                                                fill={roleInfo.bg}
                                                stroke={isSelected ? "#ffffff" : roleInfo.fill}
                                                strokeWidth={isSelected ? 3.5 : 2}
                                                strokeDasharray={node.is_discovered ? "4 2" : "none"}
                                                className="transition-all duration-300 group-hover:stroke-white group-hover:stroke-[3px]"
                                                filter={isLarge ? (isInternet ? "url(#glow-cyan)" : "url(#glow-emerald)") : undefined}
                                            />

                                            {/* Node Emoji Icon */}
                                            <text
                                                textAnchor="middle"
                                                dy={isLarge ? 8 : 6}
                                                fontSize={isLarge ? "22" : "16"}
                                            >
                                                {getNodeIcon(node.type, isCore, isInternet)}
                                            </text>

                                            {/* Status Dot */}
                                            <circle
                                                cx={isLarge ? 24 : 18}
                                                cy={isLarge ? -24 : -18}
                                                r="5"
                                                fill={node.status === "online" ? "#10b981" : "#ef4444"}
                                            />

                                            {/* Role Pill Badge */}
                                            <g transform={`translate(0, ${isLarge ? 48 : 38})`}>
                                                <rect
                                                    x="-45"
                                                    y="-9"
                                                    width="90"
                                                    height="15"
                                                    rx="4"
                                                    fill={roleInfo.fill}
                                                    opacity="0.95"
                                                />
                                                <text
                                                    x="0"
                                                    y="2"
                                                    fill="#ffffff"
                                                    fontSize="8"
                                                    fontWeight="bold"
                                                    fontFamily="sans-serif"
                                                    textAnchor="middle"
                                                >
                                                    {roleInfo.label}
                                                </text>
                                            </g>

                                            {/* Node Name Below (Truncated cleanly) */}
                                            <text
                                                textAnchor="middle"
                                                y={isLarge ? 70 : 60}
                                                fill="#ffffff"
                                                fontSize={isLarge ? "12" : "11"}
                                                fontWeight={isLarge ? "bold" : "600"}
                                                fontFamily="sans-serif"
                                            >
                                                {truncatedName}
                                            </text>

                                            {/* Sub-label IP */}
                                            <text
                                                textAnchor="middle"
                                                y={isLarge ? 84 : 72}
                                                fill="#38bdf8"
                                                fontSize="9.5"
                                                fontWeight="500"
                                                fontFamily="monospace"
                                            >
                                                {node.ip}
                                            </text>
                                        </g>
                                    );
                                })}
                            </g>
                        </svg>

                        {/* Legend Overlay */}
                        <div className="absolute bottom-4 left-4 bg-white/95 border border-slate-200 backdrop-blur-md p-3.5 rounded-xl text-xs space-y-1.5 text-slate-500 shadow-md">
                            <div className="font-bold text-slate-900 mb-1.5 text-xs flex items-center justify-between border-b border-slate-200 pb-1">
                                <span>Legenda Perangkat</span>
                                <span className="text-[10px] text-slate-500 font-normal">Tampilan Hirarki</span>
                            </div>
                            <div className="grid grid-cols-2 gap-x-4 gap-y-1">
                                <div className="flex items-center space-x-2">
                                    <span className="h-2.5 w-2.5 rounded-full bg-cyan-400"></span>
                                    <span className="text-cyan-700 font-semibold">🌐 Gateway ISP</span>
                                </div>
                                <div className="flex items-center space-x-2">
                                    <span className="h-2.5 w-2.5 rounded-full bg-emerald-400"></span>
                                    <span className="text-emerald-700 font-semibold">🔒 Core Router</span>
                                </div>
                                <div className="flex items-center space-x-2">
                                    <span className="h-2.5 w-2.5 rounded-full bg-purple-400"></span>
                                    <span className="text-purple-700">🔀 Switch</span>
                                </div>
                                <div className="flex items-center space-x-2">
                                    <span className="h-2.5 w-2.5 rounded-full bg-sky-400"></span>
                                    <span className="text-sky-700">📡 Access Point</span>
                                </div>
                                <div className="flex items-center space-x-2">
                                    <span className="h-2.5 w-2.5 rounded-full bg-amber-400"></span>
                                    <span className="text-amber-700">🖥️ Server</span>
                                </div>
                                <div className="flex items-center space-x-2">
                                    <span className="h-2.5 w-2.5 rounded-full border border-blue-400 border-dashed bg-blue-500/20"></span>
                                    <span className="text-blue-700">🔍 Terdeteksi MNDP</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Node Details Drawer Side Panel */}
                    <div className="bg-white border border-slate-200 rounded-2xl p-5 flex flex-col justify-between">
                        {selectedNode ? (
                            <div className="space-y-4">
                                <div className="flex items-center justify-between border-b border-slate-200 pb-3">
                                    <div className="flex items-center space-x-2.5">
                                        <span className="text-2xl">{getNodeIcon(selectedNode.type, selectedNode.is_core, selectedNode.is_internet)}</span>
                                        <div>
                                            <h3 className="font-bold text-slate-900 text-base leading-tight">{selectedNode.name}</h3>
                                            <span className="text-xs text-slate-500 font-mono">{selectedNode.category}</span>
                                        </div>
                                    </div>
                                    <button
                                        onClick={() => setSelectedNode(null)}
                                        className="text-slate-400 hover:text-slate-700 text-lg"
                                    >
                                        ✕
                                    </button>
                                </div>

                                <div className="space-y-3 text-xs">
                                    <div>
                                        <div className="text-slate-500 uppercase font-semibold">IP Address</div>
                                        <div className="font-mono font-bold text-emerald-700 text-sm mt-0.5">{selectedNode.ip}</div>
                                    </div>

                                    {selectedNode.mac && (
                                        <div>
                                            <div className="text-slate-500 uppercase font-semibold">MAC Address</div>
                                            <div className="font-mono text-slate-900 mt-0.5">{selectedNode.mac}</div>
                                        </div>
                                    )}

                                    <div>
                                        <div className="text-slate-500 uppercase font-semibold">Lokasi / Gedung</div>
                                        <div className="text-slate-900 font-medium mt-0.5">{selectedNode.building || "-"}</div>
                                    </div>

                                    <div>
                                        <div className="text-slate-500 uppercase font-semibold">Vendor / Model</div>
                                        <div className="text-slate-900 mt-0.5">{selectedNode.vendor || "MikroTik"} ({selectedNode.model || "-"})</div>
                                    </div>

                                    <div>
                                        <div className="text-slate-500 uppercase font-semibold">Peran Perangkat</div>
                                        <div className="mt-1">
                                            {selectedNode.is_internet ? (
                                                <span className="px-2 py-0.5 rounded bg-cyan-50 text-cyan-700 font-semibold border border-cyan-200">
                                                    🌐 Gateway Sumber Internet ISP
                                                </span>
                                            ) : selectedNode.is_core ? (
                                                <span className="px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 font-semibold border border-emerald-200">
                                                    🔒 Core Router MikroTik Utama
                                                </span>
                                            ) : selectedNode.is_discovered ? (
                                                <span className="px-2 py-0.5 rounded bg-blue-50 text-blue-700 font-semibold border border-blue-200">
                                                    🔍 Otomatis Terdeteksi via MNDP
                                                </span>
                                            ) : (
                                                <span className="px-2 py-0.5 rounded bg-purple-50 text-purple-700 font-semibold border border-purple-200">
                                                    📦 Terdaftar di Inventaris CIMS
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                </div>

                                {selectedNode.db_id && (
                                    <a
                                        href={route("devices.index") + `#device-${selectedNode.db_id}`}
                                        className="block w-full text-center py-2 bg-slate-50 hover:bg-slate-100 border border-slate-200 text-slate-800 text-xs font-semibold rounded-xl transition"
                                    >
                                        Lihat Spesifikasi Inventaris →
                                    </a>
                                )}
                            </div>
                        ) : (
                            <div className="h-full flex flex-col items-center justify-center text-center text-slate-500 p-6 space-y-3">
                                <div className="text-4xl">👆</div>
                                <div className="font-semibold text-slate-900">Pilih Perangkat</div>
                                <p className="text-xs">
                                    Klik salah satu ikon perangkat pada peta untuk memeriksa interface fisik, alamat IP/MAC, lokasi, dan detail data inventaris.
                                </p>
                            </div>
                        )}

                        <div className="pt-4 border-t border-slate-200 text-center text-xs text-slate-500">
                            Klik perangkat untuk menyorot jalur koneksi
                        </div>
                    </div>
                    </div>
                </div>
        </CimsLayout>
    );
}
