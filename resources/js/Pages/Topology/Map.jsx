import React, { useState, useEffect, useMemo } from "react";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
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
        <AuthenticatedLayout user={auth.user}>
            <Head title="Interactive Network Topology Map" />

            <div className="space-y-6">
                {/* Header Section */}
                <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-brand-card border border-brand-border p-6 rounded-2xl">
                    <div className="flex items-center space-x-4">
                        <div className="h-12 w-12 rounded-xl bg-purple-500/10 border border-purple-500/30 flex items-center justify-center text-purple-400 font-bold text-2xl">
                            🕸️
                        </div>
                        <div>
                            <div className="flex items-center space-x-3">
                                <h1 className="text-2xl font-bold text-white tracking-wide">
                                    Live Network Topology Map
                                </h1>
                                <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                                    <span className="h-2 w-2 rounded-full bg-emerald-400 mr-1.5 animate-ping"></span>
                                    Hierarchical Tier Layout
                                </span>
                            </div>
                            <p className="text-sm text-brand-textSecondary mt-1">
                                Hierarchical layout mapping ISP WAN Source → Core Router → Switches & Access Points → End Devices
                            </p>
                        </div>
                    </div>

                    <button
                        onClick={refreshData}
                        disabled={isRefreshing}
                        className="flex items-center justify-center space-x-2 px-4 py-2.5 bg-brand-primary hover:bg-emerald-500 text-white font-medium rounded-xl text-sm transition duration-200 disabled:opacity-50"
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
                        <span>{isRefreshing ? "Scanning Topology..." : "Rescan Topology"}</span>
                    </button>
                </div>

                {/* Summary Stat Cards */}
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <div className="bg-brand-card border border-brand-border p-4 rounded-2xl flex items-center justify-between">
                        <div>
                            <div className="text-xs text-brand-textSecondary uppercase font-semibold">Total Nodes</div>
                            <div className="text-2xl font-bold text-white mt-1">{data.stats?.total_nodes || 0}</div>
                        </div>
                        <div className="p-3 bg-brand-bgSecondary rounded-xl text-purple-400 font-bold">🗺️</div>
                    </div>

                    <div className="bg-brand-card border border-brand-border p-4 rounded-2xl flex items-center justify-between">
                        <div>
                            <div className="text-xs text-brand-textSecondary uppercase font-semibold">Online Devices</div>
                            <div className="text-2xl font-bold text-emerald-400 mt-1">{data.stats?.online_nodes || 0}</div>
                        </div>
                        <div className="p-3 bg-brand-bgSecondary rounded-xl text-emerald-400 font-bold">🟢</div>
                    </div>

                    <div className="bg-brand-card border border-brand-border p-4 rounded-2xl flex items-center justify-between">
                        <div>
                            <div className="text-xs text-brand-textSecondary uppercase font-semibold">Discovered (MNDP)</div>
                            <div className="text-2xl font-bold text-blue-400 mt-1">{data.stats?.discovered_neighbors || 0}</div>
                        </div>
                        <div className="p-3 bg-brand-bgSecondary rounded-xl text-blue-400 font-bold">📡</div>
                    </div>

                    <div className="bg-brand-card border border-brand-border p-4 rounded-2xl flex items-center justify-between">
                        <div>
                            <div className="text-xs text-brand-textSecondary uppercase font-semibold">Active Links</div>
                            <div className="text-2xl font-bold text-amber-400 mt-1">{data.stats?.total_links || 0}</div>
                        </div>
                        <div className="p-3 bg-brand-bgSecondary rounded-xl text-amber-400 font-bold">🔗</div>
                    </div>
                </div>

                {/* Search & Filter Toolbar */}
                <div className="flex flex-col sm:flex-row items-center justify-between gap-3 bg-brand-card border border-brand-border p-4 rounded-2xl">
                    {/* Search Input */}
                    <div className="relative w-full sm:w-80">
                        <input
                            type="text"
                            placeholder="Search by device name, IP, or location..."
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            className="w-full pl-10 pr-4 py-2 bg-brand-bg border border-brand-border rounded-xl text-sm text-white placeholder-brand-textSecondary focus:outline-none focus:border-brand-primary"
                        />
                        <svg
                            className="w-4 h-4 text-brand-textSecondary absolute left-3 top-3"
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
                            { id: "all", label: "All Nodes" },
                            { id: "router", label: "Routers" },
                            { id: "switch", label: "Switches" },
                            { id: "access_point", label: "Access Points" },
                            { id: "server", label: "Servers" },
                            { id: "discovered", label: "Auto-Discovered" },
                        ].map((filter) => (
                            <button
                                key={filter.id}
                                onClick={() => setFilterType(filter.id)}
                                className={`px-3 py-1.5 rounded-lg text-xs font-semibold whitespace-nowrap transition ${
                                    filterType === filter.id
                                        ? "bg-purple-600 text-white"
                                        : "bg-brand-bg text-brand-textSecondary hover:text-white hover:bg-brand-cardElevated"
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
                    <div className="lg:col-span-3 bg-brand-card border border-brand-border rounded-2xl p-4 overflow-hidden relative min-h-[720px] flex items-center justify-center">
                        {/* Floating Zoom / Pan Controls Toolbar */}
                        <div className="absolute top-4 right-4 bg-brand-cardElevated/95 border border-brand-border backdrop-blur-md p-1.5 rounded-xl flex items-center space-x-1.5 shadow-2xl z-10 select-none">
                            <button
                                onClick={handleZoomIn}
                                className="p-1.5 hover:bg-brand-bgSecondary rounded-lg text-white text-xs font-bold transition flex items-center space-x-1"
                                title="Zoom In (+)"
                            >
                                <span>🔍</span>
                                <span>+</span>
                            </button>
                            <button
                                onClick={handleZoomOut}
                                className="p-1.5 hover:bg-brand-bgSecondary rounded-lg text-white text-xs font-bold transition flex items-center space-x-1"
                                title="Zoom Out (-)"
                            >
                                <span>🔍</span>
                                <span>-</span>
                            </button>
                            <span className="px-2 text-xs font-mono text-emerald-400 font-bold border-l border-r border-brand-border">
                                {Math.round(zoom * 100)}%
                            </span>
                            <button
                                onClick={handleResetZoom}
                                className="px-2.5 py-1 bg-purple-600/30 border border-purple-500/40 hover:bg-purple-600 text-purple-200 rounded-lg text-xs font-semibold transition"
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
                        <div className="absolute bottom-4 left-4 bg-brand-cardElevated/95 border border-brand-border backdrop-blur-md p-3.5 rounded-xl text-xs space-y-1.5 text-brand-textSecondary shadow-2xl">
                            <div className="font-bold text-white mb-1.5 text-xs flex items-center justify-between border-b border-brand-border pb-1">
                                <span>Topology Role Legend</span>
                                <span className="text-[10px] text-brand-textSecondary font-normal">Tiered View</span>
                            </div>
                            <div className="grid grid-cols-2 gap-x-4 gap-y-1">
                                <div className="flex items-center space-x-2">
                                    <span className="h-2.5 w-2.5 rounded-full bg-cyan-400"></span>
                                    <span className="text-cyan-300 font-semibold">🌐 ISP Gateway</span>
                                </div>
                                <div className="flex items-center space-x-2">
                                    <span className="h-2.5 w-2.5 rounded-full bg-emerald-400"></span>
                                    <span className="text-emerald-300 font-semibold">🔒 Core Router</span>
                                </div>
                                <div className="flex items-center space-x-2">
                                    <span className="h-2.5 w-2.5 rounded-full bg-purple-400"></span>
                                    <span className="text-purple-300">🔀 Switch</span>
                                </div>
                                <div className="flex items-center space-x-2">
                                    <span className="h-2.5 w-2.5 rounded-full bg-sky-400"></span>
                                    <span className="text-sky-300">📡 Access Point</span>
                                </div>
                                <div className="flex items-center space-x-2">
                                    <span className="h-2.5 w-2.5 rounded-full bg-amber-400"></span>
                                    <span className="text-amber-300">🖥️ Server</span>
                                </div>
                                <div className="flex items-center space-x-2">
                                    <span className="h-2.5 w-2.5 rounded-full border border-blue-400 border-dashed bg-blue-500/20"></span>
                                    <span className="text-blue-300">🔍 Discovered MNDP</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Node Details Drawer Side Panel */}
                    <div className="bg-brand-card border border-brand-border rounded-2xl p-5 flex flex-col justify-between">
                        {selectedNode ? (
                            <div className="space-y-4">
                                <div className="flex items-center justify-between border-b border-brand-border pb-3">
                                    <div className="flex items-center space-x-2.5">
                                        <span className="text-2xl">{getNodeIcon(selectedNode.type, selectedNode.is_core, selectedNode.is_internet)}</span>
                                        <div>
                                            <h3 className="font-bold text-white text-base leading-tight">{selectedNode.name}</h3>
                                            <span className="text-xs text-brand-textSecondary font-mono">{selectedNode.category}</span>
                                        </div>
                                    </div>
                                    <button
                                        onClick={() => setSelectedNode(null)}
                                        className="text-brand-textSecondary hover:text-white text-lg"
                                    >
                                        ✕
                                    </button>
                                </div>

                                <div className="space-y-3 text-xs">
                                    <div>
                                        <div className="text-brand-textSecondary uppercase font-semibold">IP Address</div>
                                        <div className="font-mono font-bold text-emerald-400 text-sm mt-0.5">{selectedNode.ip}</div>
                                    </div>

                                    {selectedNode.mac && (
                                        <div>
                                            <div className="text-brand-textSecondary uppercase font-semibold">MAC Address</div>
                                            <div className="font-mono text-white mt-0.5">{selectedNode.mac}</div>
                                        </div>
                                    )}

                                    <div>
                                        <div className="text-brand-textSecondary uppercase font-semibold">Location / Building</div>
                                        <div className="text-white font-medium mt-0.5">{selectedNode.building || "-"}</div>
                                    </div>

                                    <div>
                                        <div className="text-brand-textSecondary uppercase font-semibold">Vendor / Model</div>
                                        <div className="text-white mt-0.5">{selectedNode.vendor || "MikroTik"} ({selectedNode.model || "-"})</div>
                                    </div>

                                    <div>
                                        <div className="text-brand-textSecondary uppercase font-semibold">Device Role & Origin</div>
                                        <div className="mt-1">
                                            {selectedNode.is_internet ? (
                                                <span className="px-2 py-0.5 rounded bg-cyan-500/10 text-cyan-400 font-semibold border border-cyan-500/30">
                                                    🌐 ISP Internet Source Gateway
                                                </span>
                                            ) : selectedNode.is_core ? (
                                                <span className="px-2 py-0.5 rounded bg-emerald-500/10 text-emerald-400 font-semibold border border-emerald-500/30">
                                                    🔒 MikroTik Primary Core Router
                                                </span>
                                            ) : selectedNode.is_discovered ? (
                                                <span className="px-2 py-0.5 rounded bg-blue-500/10 text-blue-400 font-semibold border border-blue-500/30">
                                                    🔍 Auto-Discovered via MNDP Neighbor
                                                </span>
                                            ) : (
                                                <span className="px-2 py-0.5 rounded bg-purple-500/10 text-purple-400 font-semibold border border-purple-500/30">
                                                    📦 Registered in CIMS Inventory
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                </div>

                                {selectedNode.db_id && (
                                    <a
                                        href={route("devices.index") + `#device-${selectedNode.db_id}`}
                                        className="block w-full text-center py-2 bg-brand-bgSecondary hover:bg-brand-cardElevated border border-brand-border text-white text-xs font-semibold rounded-xl transition"
                                    >
                                        View Full Inventory Specs →
                                    </a>
                                )}
                            </div>
                        ) : (
                            <div className="h-full flex flex-col items-center justify-center text-center text-brand-textSecondary p-6 space-y-3">
                                <div className="text-4xl">👆</div>
                                <div className="font-semibold text-white">Select Any Node</div>
                                <p className="text-xs">
                                    Click any device node to inspect physical interfaces, IP/MAC addresses, location, and CIMS Inventory records.
                                </p>
                            </div>
                        )}

                        <div className="pt-4 border-t border-brand-border text-center text-xs text-brand-textSecondary">
                            Click node to highlight connections
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
