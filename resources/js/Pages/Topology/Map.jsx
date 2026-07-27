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

    // Calculate node positions in an interactive radial layout
    const positionedNodes = useMemo(() => {
        const nodes = [...filteredNodes];
        if (nodes.length === 0) return [];

        const centerX = 450;
        const centerY = 320;
        const coreNode = nodes.find((n) => n.is_core) || nodes[0];
        const otherNodes = nodes.filter((n) => n.id !== coreNode.id);

        const radius = Math.min(260, Math.max(160, otherNodes.length * 35));
        const total = otherNodes.length;

        const positions = {};
        positions[coreNode.id] = { x: centerX, y: centerY, ...coreNode };

        otherNodes.forEach((node, idx) => {
            const angle = (idx / total) * 2 * Math.PI - Math.PI / 2;
            const x = centerX + radius * Math.cos(angle);
            const y = centerY + radius * Math.sin(angle);
            positions[node.id] = { x, y, ...node };
        });

        return positions;
    }, [filteredNodes]);

    // Node icon helper
    const getNodeIcon = (type, isCore) => {
        if (isCore) return "🌐";
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
                                    MNDP Auto-Discovery Active
                                </span>
                            </div>
                            <p className="text-sm text-brand-textSecondary mt-1">
                                Physical & logical network links discovered via MikroTik MNDP/CDP API and CIMS Inventory
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
                    <div className="lg:col-span-3 bg-brand-card border border-brand-border rounded-2xl p-4 overflow-hidden relative min-h-[550px] flex items-center justify-center">
                        <svg className="w-full h-[540px] select-none" viewBox="0 0 900 640">
                            <defs>
                                {/* Glowing Filter for Links */}
                                <filter id="glow-emerald" x="-20%" y="-20%" width="140%" height="140%">
                                    <feGaussianBlur stdDeviation="3" result="blur" />
                                    <feComposite in="SourceGraphic" in2="blur" operator="over" />
                                </filter>

                                {/* Animated Dash Pattern for Packet Flow */}
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

                            {/* Render Links (Connection Lines) */}
                            {(data.links || []).map((link) => {
                                const sourcePos = positionedNodes[link.source];
                                const targetPos = positionedNodes[link.target];

                                if (!sourcePos || !targetPos) return null;

                                const isSelected =
                                    selectedNode &&
                                    (selectedNode.id === link.source || selectedNode.id === link.target);

                                return (
                                    <g key={link.id}>
                                        {/* Outer Glow Path */}
                                        <line
                                            x1={sourcePos.x}
                                            y1={sourcePos.y}
                                            x2={targetPos.x}
                                            y2={targetPos.y}
                                            stroke={isSelected ? "#10b981" : "rgba(139, 92, 246, 0.4)"}
                                            strokeWidth={isSelected ? 4 : 2}
                                            strokeDasharray={isSelected ? "8 4" : "4 4"}
                                            className={isSelected ? "animate-pulse" : ""}
                                        />

                                        {/* Interface Badge along the Link */}
                                        {link.source_interface && (
                                            <text
                                                x={(sourcePos.x + targetPos.x) / 2}
                                                y={(sourcePos.y + targetPos.y) / 2 - 6}
                                                fill="#9ca3af"
                                                fontSize="10"
                                                fontFamily="monospace"
                                                textAnchor="middle"
                                                className="bg-black/80 px-1 rounded"
                                            >
                                                {link.source_interface}
                                            </text>
                                        )}
                                    </g>
                                );
                            })}

                            {/* Render Nodes */}
                            {Object.values(positionedNodes).map((node) => {
                                const isSelected = selectedNode?.id === node.id;
                                const isCore = node.is_core;

                                return (
                                    <g
                                        key={node.id}
                                        transform={`translate(${node.x}, ${node.y})`}
                                        onClick={() => setSelectedNode(node)}
                                        className="cursor-pointer group"
                                    >
                                        {/* Core Node Pulse Effect */}
                                        {isCore && (
                                            <circle
                                                r="42"
                                                fill="none"
                                                stroke="#10b981"
                                                strokeWidth="1.5"
                                                className="animate-ping opacity-30"
                                            />
                                        )}

                                        {/* Node Outer Selection Ring */}
                                        <circle
                                            r={isSelected ? (isCore ? 38 : 30) : (isCore ? 34 : 26)}
                                            fill={
                                                isCore
                                                    ? "rgba(16, 185, 129, 0.2)"
                                                    : node.is_discovered
                                                    ? "rgba(59, 130, 246, 0.2)"
                                                    : "rgba(30, 41, 59, 0.9)"
                                            }
                                            stroke={
                                                isSelected
                                                    ? "#10b981"
                                                    : isCore
                                                    ? "#10b981"
                                                    : node.is_discovered
                                                    ? "#3b82f6"
                                                    : "#64748b"
                                            }
                                            strokeWidth={isSelected ? 3.5 : 2}
                                            className="transition-all duration-300 group-hover:stroke-emerald-400 group-hover:stroke-[3px]"
                                            filter={isCore ? "url(#glow-emerald)" : undefined}
                                        />

                                        {/* Node Emoji Icon */}
                                        <text
                                            textAnchor="middle"
                                            dy={isCore ? 8 : 6}
                                            fontSize={isCore ? "22" : "16"}
                                        >
                                            {getNodeIcon(node.type, isCore)}
                                        </text>

                                        {/* Status Dot */}
                                        <circle
                                            cx={isCore ? 24 : 18}
                                            cy={isCore ? -24 : -18}
                                            r="5"
                                            fill={node.status === "online" ? "#10b981" : "#ef4444"}
                                        />

                                        {/* Node Label Below */}
                                        <text
                                            textAnchor="middle"
                                            y={isCore ? 52 : 44}
                                            fill="#ffffff"
                                            fontSize={isCore ? "13" : "11"}
                                            fontWeight={isCore ? "bold" : "normal"}
                                            fontFamily="sans-serif"
                                        >
                                            {node.name}
                                        </text>

                                        {/* Sub-label IP */}
                                        <text
                                            textAnchor="middle"
                                            y={isCore ? 66 : 56}
                                            fill="#9ca3af"
                                            fontSize="9"
                                            fontFamily="monospace"
                                        >
                                            {node.ip}
                                        </text>
                                    </g>
                                );
                            })}
                        </svg>

                        {/* Legend Overlay */}
                        <div className="absolute bottom-4 left-4 bg-brand-cardElevated/90 border border-brand-border backdrop-blur-md p-3 rounded-xl text-xs space-y-1.5 text-brand-textSecondary">
                            <div className="font-bold text-white mb-1">Topology Legend</div>
                            <div className="flex items-center space-x-2">
                                <span className="h-2.5 w-2.5 rounded-full bg-emerald-400"></span>
                                <span>Core Router (MikroTik)</span>
                            </div>
                            <div className="flex items-center space-x-2">
                                <span className="h-2.5 w-2.5 rounded-full bg-blue-400"></span>
                                <span>Auto-Discovered (MNDP/CDP)</span>
                            </div>
                            <div className="flex items-center space-x-2">
                                <span className="h-2.5 w-2.5 rounded-full bg-slate-400"></span>
                                <span>CIMS Registered Devices</span>
                            </div>
                        </div>
                    </div>

                    {/* Node Details Drawer Side Panel */}
                    <div className="bg-brand-card border border-brand-border rounded-2xl p-5 flex flex-col justify-between">
                        {selectedNode ? (
                            <div className="space-y-4">
                                <div className="flex items-center justify-between border-b border-brand-border pb-3">
                                    <div className="flex items-center space-x-2">
                                        <span className="text-2xl">{getNodeIcon(selectedNode.type, selectedNode.is_core)}</span>
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
                                        <div className="text-brand-textSecondary uppercase font-semibold">Link Discovery</div>
                                        <div className="mt-0.5">
                                            {selectedNode.is_discovered ? (
                                                <span className="px-2 py-0.5 rounded bg-blue-500/10 text-blue-400 font-semibold border border-blue-500/30">
                                                    Discovered via MNDP Neighbor
                                                </span>
                                            ) : (
                                                <span className="px-2 py-0.5 rounded bg-emerald-500/10 text-emerald-400 font-semibold border border-emerald-500/30">
                                                    Registered Inventory Device
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
                                <div className="font-semibold text-white">Click Any Device Node</div>
                                <p className="text-xs">
                                    Select any node in the topology visualizer to view live link details, IP/MAC addresses, and location specs.
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
